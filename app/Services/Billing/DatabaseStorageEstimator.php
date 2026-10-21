<?php

namespace App\Services\Billing;

use Illuminate\Support\Facades\DB;

/**
 * Estimates the PostgreSQL space attributable to each organization.
 * Read-only: only SELECTs against the catalog and the tables.
 *
 * Tables are discovered from the live schema, so a future table with an
 * `engage_organization_location_id` column (or a foreign key to one that
 * has it) is included automatically. Each table is attributed by exactly
 * one strategy, so nothing is counted twice:
 *
 *   organization  engage_organization_locations itself (row id = org id)
 *   direct        the table has engage_organization_location_id
 *   shared        no org column, but a junction table that has one points
 *                 at it (engage_customers via engage_customers_locations,
 *                 engage_users via engage_users_locations): each row is
 *                 split equally between the organizations it is linked to
 *   child         no org column, a foreign key to an already-attributed
 *                 table (engage_product_rentals -> engage_products,
 *                 pivots, transaction items, verifications ...)
 *
 * Row bytes come from pg_column_size(row). Each organization's share of a
 * table is then scaled by pg_total_relation_size / total row bytes, which
 * spreads indexes, TOAST and page overhead in proportion to row data.
 * Tables in config('usage.database.excluded_tables'), tables that can't be
 * attributed and rows that match no organization are reported as
 * unattributed and billed to no one. It is an estimate.
 */
class DatabaseStorageEstimator
{
    private const ORG_COLUMN = 'engage_organization_location_id';

    private const ORG_TABLE = 'engage_organization_locations';

    public function estimate(): array
    {
        if (DB::getDriverName() !== 'pgsql') {
            return $this->failed('Database storage can only be estimated on PostgreSQL.');
        }

        $errors = [];
        $organizations = [];
        $tables = [];
        $totals = ['database_bytes' => 0, 'attributed_bytes' => 0.0, 'unattributed_bytes' => 0.0];

        try {
            $schema = $this->discoverSchema();
            $plan = $this->planAttribution($schema);
        } catch (\Throwable $e) {
            report($e);

            return $this->failed('Could not read the database schema: '.$e->getMessage());
        }

        $timeout = max(1000, (int) config('usage.database.statement_timeout_ms', 60000));

        foreach ($schema['tables'] as $table) {
            try {
                $totalRelation = (int) DB::selectOne('SELECT pg_total_relation_size(?::regclass) AS bytes', [$this->quote($table)])->bytes;
            } catch (\Throwable $e) {
                $errors[] = "{$table}: size unavailable ({$e->getMessage()})";

                continue;
            }
            $totals['database_bytes'] += $totalRelation;

            $strategy = $plan[$table] ?? null;
            if ($strategy === null) {
                $totals['unattributed_bytes'] += $totalRelation;
                $tables[$table] = ['strategy' => in_array($table, $schema['excluded'], true) ? 'excluded' : 'unattributed', 'total_bytes' => $totalRelation, 'attributed_bytes' => 0];

                continue;
            }

            try {
                $rows = $this->withTimeout($timeout, fn () => $this->attributedRowBytes($table, $strategy, $plan));
                $rowTotal = (float) $this->withTimeout($timeout, fn () => DB::selectOne('SELECT COALESCE(SUM(pg_column_size(t.*)), 0) AS bytes FROM '.$this->quote($table).' t')->bytes);
            } catch (\Throwable $e) {
                $errors[] = "{$table}: {$e->getMessage()}";
                $tables[$table] = ['strategy' => $strategy['type'], 'total_bytes' => $totalRelation, 'attributed_bytes' => 0, 'error' => true];

                continue;
            }

            // pg_column_size of a row differs by a few bytes between grouped
            // and plain queries; never attribute more than the table's size.
            $rowTotal = max($rowTotal, (float) collect($rows)->sum(fn ($row) => (float) $row->bytes));
            $ratio = $rowTotal > 0 ? $totalRelation / $rowTotal : 0.0;
            $attributed = 0.0;
            foreach ($rows as $row) {
                if ($row->org === null) {
                    continue;
                }
                $bytes = (float) $row->bytes * $ratio;
                $organizations[$row->org] = ($organizations[$row->org] ?? 0.0) + $bytes;
                $attributed += $bytes;
            }

            $totals['attributed_bytes'] += $attributed;
            $totals['unattributed_bytes'] += max(0, $totalRelation - $attributed);
            $tables[$table] = ['strategy' => $strategy['type'], 'total_bytes' => $totalRelation, 'attributed_bytes' => (int) round($attributed)];
        }

        ksort($tables);

        return [
            'available' => true,
            'complete' => $errors === [],
            'errors' => $errors,
            'organizations' => array_map(fn ($bytes) => (int) round($bytes), $organizations),
            'summary' => [
                'database_bytes' => $totals['database_bytes'],
                'attributed_bytes' => (int) round($totals['attributed_bytes']),
                'unattributed_bytes' => (int) round($totals['unattributed_bytes']),
                'tables' => $tables,
            ],
        ];
    }

    /** Base tables in the current schema with their columns and foreign keys. */
    private function discoverSchema(): array
    {
        $excluded = (array) config('usage.database.excluded_tables', []);

        $tables = collect(DB::select("
            SELECT table_name FROM information_schema.tables
            WHERE table_schema = current_schema() AND table_type = 'BASE TABLE'
            ORDER BY table_name
        "))->pluck('table_name')
            ->filter(fn ($name) => preg_match('/^[a-z0-9_]+$/', $name) === 1)
            ->values()->all();

        $columns = [];
        foreach (DB::select('SELECT table_name, column_name FROM information_schema.columns WHERE table_schema = current_schema()') as $row) {
            $columns[$row->table_name][$row->column_name] = true;
        }

        $foreignKeys = [];
        foreach (DB::select("
            SELECT kcu.table_name, kcu.column_name, ccu.table_name AS foreign_table, ccu.column_name AS foreign_column
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu ON kcu.constraint_name = tc.constraint_name AND kcu.table_schema = tc.table_schema
            JOIN information_schema.constraint_column_usage ccu ON ccu.constraint_name = tc.constraint_name AND ccu.table_schema = tc.table_schema
            WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_schema = current_schema()
            ORDER BY kcu.table_name, kcu.column_name
        ") as $row) {
            $foreignKeys[$row->table_name][] = ['column' => $row->column_name, 'table' => $row->foreign_table, 'foreign_column' => $row->foreign_column];
        }

        return ['tables' => $tables, 'columns' => $columns, 'foreign_keys' => $foreignKeys, 'excluded' => $excluded];
    }

    /** Picks one attribution strategy per table (see class comment). */
    private function planAttribution(array $schema): array
    {
        $plan = [];
        $candidates = array_values(array_diff($schema['tables'], $schema['excluded']));

        if (in_array(self::ORG_TABLE, $candidates, true)) {
            $plan[self::ORG_TABLE] = ['type' => 'organization'];
        }

        foreach ($candidates as $table) {
            if (! isset($plan[$table]) && isset($schema['columns'][$table][self::ORG_COLUMN])) {
                $plan[$table] = ['type' => 'direct'];
            }
        }

        // Children of what is attributed so far (a foreign key to it), then
        // rows shared through a link table, then children of those.
        $this->addChildren($plan, $candidates, $schema);

        // Shared through a link table: a direct table that only links one
        // other table to organizations (engage_customers_locations,
        // engage_users_locations), not a business table that merely has a
        // foreign key to it.
        foreach ($candidates as $table) {
            if (isset($plan[$table]) || ! isset($schema['columns'][$table]['id'])) {
                continue;
            }
            foreach ($candidates as $junction) {
                if (($plan[$junction]['type'] ?? null) !== 'direct' || ! $this->isLinkTable($junction, $schema)) {
                    continue;
                }
                foreach ($schema['foreign_keys'][$junction] ?? [] as $fk) {
                    if ($fk['table'] === $table && $fk['foreign_column'] === 'id' && $fk['column'] !== self::ORG_COLUMN) {
                        $plan[$table] = ['type' => 'shared', 'junction' => $junction, 'column' => $fk['column']];
                        break 2;
                    }
                }
            }
        }

        $this->addChildren($plan, $candidates, $schema);

        return $plan;
    }

    /** Adds tables with a foreign key to an attributed table's id (up to 4 levels). */
    private function addChildren(array &$plan, array $candidates, array $schema): void
    {
        for ($pass = 0; $pass < 4; $pass++) {
            $added = false;
            foreach ($candidates as $table) {
                if (isset($plan[$table])) {
                    continue;
                }
                foreach ($schema['foreign_keys'][$table] ?? [] as $fk) {
                    $parent = $plan[$fk['table']] ?? null;
                    if ($parent === null || $fk['foreign_column'] !== 'id' || ($parent['type'] !== 'organization' && ! isset($schema['columns'][$fk['table']]['id']))) {
                        continue;
                    }
                    $plan[$table] = ['type' => 'child', 'parent' => $fk['table'], 'column' => $fk['column']];
                    $added = true;
                    break;
                }
            }
            if (! $added) {
                return;
            }
        }
    }

    /** A small table whose only job is linking one other table to organizations. */
    private function isLinkTable(string $table, array $schema): bool
    {
        $foreignKeys = array_filter($schema['foreign_keys'][$table] ?? [], fn ($fk) => $fk['column'] !== self::ORG_COLUMN);

        return count($foreignKeys) === 1 && count($schema['columns'][$table] ?? []) <= 6;
    }

    /** SQL returning (id, org, share) for every row of an attributed table. */
    private function ownershipSql(string $table, array $plan, int $depth = 0): string
    {
        $strategy = $plan[$table];
        $t = $this->quote($table);

        return match ($strategy['type']) {
            'organization' => "SELECT id::text AS id, id::text AS org, 1::numeric AS share FROM {$t}",
            'direct' => 'SELECT id::text AS id, '.self::ORG_COLUMN."::text AS org, 1::numeric AS share FROM {$t}",
            'shared' => $this->sharedOwnershipSql($strategy),
            'child' => $depth > 5
                ? throw new \RuntimeException("Ownership chain too deep for {$table}.")
                : "SELECT c.id::text AS id, o.org, o.share FROM {$t} c JOIN (".$this->ownershipSql($strategy['parent'], $plan, $depth + 1).') o ON o.id = c.'.$this->quote($strategy['column']).'::text',
        };
    }

    private function sharedOwnershipSql(array $strategy): string
    {
        $junction = $this->quote($strategy['junction']);
        $column = $this->quote($strategy['column']);
        $org = self::ORG_COLUMN;

        return "SELECT l.id, l.org, 1::numeric / COUNT(*) OVER (PARTITION BY l.id) AS share
                FROM (SELECT DISTINCT {$column}::text AS id, {$org}::text AS org FROM {$junction}) l";
    }

    /** @return list<object{org: ?string, bytes: string|float}> */
    private function attributedRowBytes(string $table, array $strategy, array $plan): array
    {
        $t = $this->quote($table);

        return match ($strategy['type']) {
            'organization' => DB::select("SELECT t.id::text AS org, SUM(pg_column_size(t.*)) AS bytes FROM {$t} t GROUP BY t.id"),
            'direct' => DB::select('SELECT t.'.self::ORG_COLUMN."::text AS org, SUM(pg_column_size(t.*)) AS bytes FROM {$t} t GROUP BY 1"),
            'shared' => DB::select("SELECT o.org, SUM(pg_column_size(t.*) * o.share) AS bytes FROM {$t} t JOIN (".$this->sharedOwnershipSql($strategy).') o ON o.id = t.id::text GROUP BY o.org'),
            'child' => DB::select("SELECT o.org, SUM(pg_column_size(t.*) * o.share) AS bytes FROM {$t} t JOIN (".$this->ownershipSql($strategy['parent'], $plan).') o ON o.id = t.'.$this->quote($strategy['column']).'::text GROUP BY o.org'),
        };
    }

    /** Runs a measurement query with a statement time limit, without leaving the setting behind. */
    private function withTimeout(int $milliseconds, \Closure $callback): mixed
    {
        $previous = DB::selectOne('SHOW statement_timeout')->statement_timeout;
        DB::statement('SET statement_timeout = '.(int) $milliseconds);
        try {
            return $callback();
        } finally {
            DB::statement('SET statement_timeout = '.DB::getPdo()->quote((string) $previous));
        }
    }

    private function quote(string $identifier): string
    {
        if (preg_match('/^[a-z0-9_]+$/', $identifier) !== 1) {
            throw new \InvalidArgumentException("Unsafe identifier [{$identifier}].");
        }

        return '"'.$identifier.'"';
    }

    private function failed(string $error): array
    {
        return ['available' => false, 'complete' => false, 'errors' => [$error], 'organizations' => [], 'summary' => null];
    }
}

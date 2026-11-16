<?php

namespace App\Http\Controllers\Api\V1\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\WebhookLog;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Webhook Logs — superadmin only, every organization. Read-only view of
 * webhook_logs: inbound Lead Connector webhooks and our outbound Lead
 * Connector calls (event type `outbound.*`).
 */
class WebhookLogController extends Controller
{
    private const SORTS = ['created_at', 'event_type', 'source', 'status', 'organization'];

    /** Organization filter value for rows with no organization. */
    private const NO_ORGANIZATION = 'none';

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'organization_ids' => ['nullable', 'string'],
            'event_types' => ['nullable', 'string'],
            'sources' => ['nullable', 'string'],
            'statuses' => ['nullable', 'string'],
            'direction' => ['nullable', Rule::in(['inbound', 'outbound'])],
            'search' => ['nullable', 'string', 'max:200'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'timezone' => ['nullable', 'timezone:all'],
            'sort_by' => ['nullable', Rule::in(self::SORTS)],
            'sort_dir' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = WebhookLog::query()->with('organizationLocation:id,name,engage_location_id');
        $this->applyFilters($query, $filters);
        $this->applySort($query, $filters['sort_by'] ?? 'created_at', $filters['sort_dir'] ?? 'desc');

        $page = $query->paginate((int) ($filters['per_page'] ?? 25));

        return response()->json([
            'success' => true,
            'data' => [
                'data' => collect($page->items())->map(fn (WebhookLog $log) => $this->present($log))->values(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show(WebhookLog $webhookLog): JsonResponse
    {
        $webhookLog->loadMissing('organizationLocation:id,name,engage_location_id');

        return response()->json([
            'success' => true,
            'data' => $this->present($webhookLog) + [
                'payload' => $webhookLog->payload,
                'updated_at' => $webhookLog->updated_at?->toIso8601String(),
            ],
        ]);
    }

    /** Filter options with row counts. */
    public function filters(): JsonResponse
    {
        $count = fn (string $column) => WebhookLog::query()
            ->select($column, DB::raw('count(*) as total'))
            ->groupBy($column)
            ->orderBy($column)
            ->get()
            ->map(fn ($row) => ['value' => $row->{$column}, 'count' => (int) $row->total])
            ->values();

        $organizations = DB::table('engage_organization_locations as o')
            ->leftJoin('webhook_logs as w', 'w.'.WebhookLog::LOCATION_COLUMN, '=', 'o.id')
            ->groupBy('o.id', 'o.name', 'o.engage_location_id')
            ->orderBy('o.name')
            ->get(['o.id', 'o.name', 'o.engage_location_id', DB::raw('count(w.id) as total')])
            ->map(fn ($row) => [
                'value' => $row->id,
                'name' => $row->name,
                'engage_location_id' => $row->engage_location_id,
                'count' => (int) $row->total,
            ])
            ->push([
                'value' => self::NO_ORGANIZATION,
                'name' => 'No organization',
                'engage_location_id' => null,
                'count' => WebhookLog::whereNull(WebhookLog::LOCATION_COLUMN)->count(),
            ])
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'event_types' => $count('event_type'),
                'sources' => $count('source'),
                'statuses' => $count('status'),
                'organizations' => $organizations,
            ],
        ]);
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        $organizationIds = $this->csv($filters['organization_ids'] ?? null);
        if ($organizationIds) {
            $withNone = in_array(self::NO_ORGANIZATION, $organizationIds, true);
            $ids = array_values(array_diff($organizationIds, [self::NO_ORGANIZATION]));

            $query->where(function (Builder $q) use ($ids, $withNone) {
                if ($ids) {
                    $q->whereIn(WebhookLog::LOCATION_COLUMN, $ids);
                }
                if ($withNone) {
                    $q->orWhereNull(WebhookLog::LOCATION_COLUMN);
                }
            });
        }

        foreach (['event_types' => 'event_type', 'sources' => 'source', 'statuses' => 'status'] as $param => $column) {
            if ($values = $this->csv($filters[$param] ?? null)) {
                $query->whereIn($column, $values);
            }
        }

        if (($filters['direction'] ?? null) === 'outbound') {
            $query->where('event_type', 'like', WebhookLog::OUTBOUND_PREFIX.'%');
        } elseif (($filters['direction'] ?? null) === 'inbound') {
            $query->where('event_type', 'not like', WebhookLog::OUTBOUND_PREFIX.'%');
        }

        // Any text in the payload (an invoice id, a contact email) or the event type.
        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $like = '%'.mb_strtolower($search).'%';
            $payloadText = DB::getDriverName() === 'pgsql' ? 'payload::text' : 'payload';
            $query->where(function (Builder $q) use ($like, $payloadText) {
                $q->whereRaw('LOWER(event_type) LIKE ?', [$like])
                    ->orWhereRaw("LOWER({$payloadText}) LIKE ?", [$like])
                    ->orWhereRaw('LOWER(id) LIKE ?', [$like]);
            });
        }

        // Whole days in the viewer's timezone.
        $timezone = $filters['timezone'] ?? config('app.timezone');
        if (! empty($filters['date_from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['date_from'], $timezone)->startOfDay()->utc());
        }
        if (! empty($filters['date_to'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['date_to'], $timezone)->endOfDay()->utc());
        }
    }

    private function applySort(Builder $query, string $sortBy, string $direction): void
    {
        if ($sortBy === 'organization') {
            $query->orderBy(
                DB::table('engage_organization_locations')
                    ->select('name')
                    ->whereColumn('engage_organization_locations.id', 'webhook_logs.'.WebhookLog::LOCATION_COLUMN)
                    ->limit(1),
                $direction
            );
        } else {
            $query->orderBy($sortBy, $direction);
        }

        // Rows logged in the same second keep a stable order.
        $query->orderBy('id', $direction);
    }

    private function present(WebhookLog $log): array
    {
        $payload = is_array($log->payload) ? $log->payload : [];
        $organization = $log->organizationLocation;

        return [
            'id' => $log->id,
            'source' => $log->source,
            'event_type' => $log->event_type,
            'direction' => $log->isOutbound() ? 'outbound' : 'inbound',
            'status' => $log->status,
            'ghl_location_id' => WebhookLog::ghlLocationIdFromPayload($payload),
            'organization' => $organization ? [
                'id' => $organization->id,
                'name' => $organization->name,
                'engage_location_id' => $organization->engage_location_id,
            ] : null,
            'summary' => $this->summary($payload),
            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }

    /**
     * A one-line description from the payload's most telling fields, e.g.
     * "Invoice #997651 · waqas sahh · USD 228". Webhooks have a flat payload;
     * outbound logs wrap it in request/response.
     */
    private function summary(array $payload): ?string
    {
        $parts = [];
        $invoice = $this->firstValue($payload, ['invoiceNumber']);
        $contact = $payload['contactDetails'] ?? $payload['contact'] ?? $payload['request']['contactDetails'] ?? null;
        $contactName = is_array($contact) ? $this->firstValue($contact, ['name', 'firstName']) : null;
        $email = $this->firstValue($payload, ['email']);
        $amount = $this->firstValue($payload, ['total', 'amount', 'totalAmount']);
        $currency = $this->firstValue($payload, ['currency']);

        if ($invoice !== null) {
            // An invoice's `title` is always "INVOICE"; its `name` says what it was for.
            $parts[] = 'Invoice #'.$invoice;
            $parts[] = $this->firstValue($payload, ['name']);
            $parts[] = $contactName;
        } else {
            $parts[] = $this->firstValue($payload, ['title']) ?? $contactName ?? $this->firstValue($payload, ['contactName', 'name', 'firstName']);
        }

        if ($email !== null && ! in_array($email, $parts, true)) {
            $parts[] = $email;
        }
        if ($amount !== null && is_numeric($amount)) {
            $parts[] = trim(($currency ?? '').' '.$amount);
        }

        $parts = array_values(array_unique(array_filter($parts, fn ($p) => $p !== null && $p !== '')));

        return $parts ? mb_strimwidth(implode(' · ', $parts), 0, 160, '…') : null;
    }

    /** First non-empty scalar under any of the keys, top level first. */
    private function firstValue(array $payload, array $keys, int $depth = 0): ?string
    {
        foreach ($keys as $key) {
            if (isset($payload[$key]) && is_scalar($payload[$key]) && $payload[$key] !== '') {
                return (string) $payload[$key];
            }
        }

        if ($depth >= 4) {
            return null;
        }

        foreach ($payload as $value) {
            if (is_array($value) && ($found = $this->firstValue($value, $keys, $depth + 1)) !== null) {
                return $found;
            }
        }

        return null;
    }

    private function csv(?string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $value)), fn ($v) => $v !== ''));
    }
}

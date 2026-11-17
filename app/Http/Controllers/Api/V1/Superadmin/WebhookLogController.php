<?php

namespace App\Http\Controllers\Api\V1\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\EngageBooking;
use App\Models\EngageProductRental;
use App\Models\EngageRentalTransaction;
use App\Models\WebhookLog;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
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

    /** Linked-record filter value for rows not linked to any record. */
    private const NO_RECORD = 'none';

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'organization_ids' => ['nullable', 'string'],
            'event_types' => ['nullable', 'string'],
            'sources' => ['nullable', 'string'],
            'statuses' => ['nullable', 'string'],
            'record_types' => ['nullable', 'string'],
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
        $this->withLoggable($query);
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
        $webhookLog->loadMorph('loggable', self::LOGGABLE_RELATIONS);

        return response()->json([
            'success' => true,
            'data' => $this->present($webhookLog) + [
                'record_details' => $this->recordDetails($webhookLog),
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
                'record_types' => WebhookLog::query()
                    ->select('loggable_type', DB::raw('count(*) as total'))
                    ->groupBy('loggable_type')
                    ->get()
                    ->map(fn ($row) => ['value' => $row->loggable_type ?? self::NO_RECORD, 'count' => (int) $row->total])
                    ->sortBy(fn ($row) => $row['value'] === self::NO_RECORD ? 'zz' : $row['value'])
                    ->values(),
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

        if ($recordTypes = $this->csv($filters['record_types'] ?? null)) {
            $withNone = in_array(self::NO_RECORD, $recordTypes, true);
            $types = array_values(array_intersect($recordTypes, array_keys(WebhookLog::LOGGABLE_TYPES)));

            $query->where(function (Builder $q) use ($types, $withNone) {
                if ($types) {
                    $q->whereIn('loggable_type', $types);
                }
                if ($withNone) {
                    $q->orWhereNull('loggable_type');
                }
                if (! $types && ! $withNone) {
                    $q->whereRaw('1 = 0');
                }
            });
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
            'record' => $this->presentRecord($log),
            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }

    /** Relations loaded with each kind of linked record, for its label. */
    private const LOGGABLE_RELATIONS = [
        EngageBooking::class => ['customer:id,name', 'product:id,name'],
        EngageRentalTransaction::class => ['product:id,name'],
        EngageProductRental::class => ['product:id,name'],
    ];

    private function withLoggable(Builder $query): void
    {
        $query->with(['loggable' => fn (MorphTo $morph) => $morph->morphWith(self::LOGGABLE_RELATIONS)]);
    }

    /**
     * The record this log is linked to, with the ids the page needs to open
     * its popup. `missing` = linked record was deleted since.
     */
    private function presentRecord(WebhookLog $log): ?array
    {
        if (! $log->loggable_type) {
            return null;
        }

        $record = $log->loggable;
        $base = ['type' => $log->loggable_type, 'id' => $log->loggable_id];

        if (! $record) {
            return $base + ['label' => 'Deleted', 'missing' => true];
        }

        $short = fn (string $id) => '#'.strtoupper(substr($id, -8));

        return $base + ['missing' => false] + match ($log->loggable_type) {
            'booking' => [
                'label' => trim($short($record->id).' · '.($record->customer?->name ?? '').' · '.($record->product?->name ?? ''), ' ·'),
                'booking_id' => $record->id,
                'customer_id' => $record->customer_id,
                'product_id' => $record->product_id,
            ],
            'campsite_transaction' => [
                'label' => trim($short($record->id).' · '.($record->product?->name ?? ''), ' ·'),
                'booking_id' => $record->booking_id,
                'customer_id' => $record->customer_id,
                'product_id' => $record->product_id,
            ],
            'product_transaction' => [
                'label' => $record->ghl_invoice_number ? 'Invoice '.$record->ghl_invoice_number : $short($record->id),
                'customer_id' => $record->customer_id,
            ],
            'customer' => [
                'label' => $record->name ?: ($record->email ?: $short($record->id)),
                'customer_id' => $record->id,
            ],
            'campsite' => [
                'label' => $record->product?->name ?: ($record->name ?: $short($record->id)),
                'product_id' => $record->product_id,
            ],
            default => ['label' => $short((string) $record->getKey())],
        };
    }

    /**
     * Key facts about the linked record, as label/value rows for the log's
     * popup (superadmin can't open the organization-scoped record pages).
     *
     * @return array<int, array{label: string, value: string}>
     */
    private function recordDetails(WebhookLog $log): array
    {
        $record = $log->loggable;
        if (! $record) {
            return [];
        }

        $date = fn ($value) => $value ? Carbon::parse($value)->toDateString() : null;
        $money = fn ($value) => $value !== null ? number_format((float) $value, 2) : null;

        $rows = match ($log->loggable_type) {
            'booking' => [
                'Customer' => $record->customer?->name,
                'Campsite' => $record->product?->name,
                'Stay' => trim(($date($record->check_in_date) ?? '').' → '.($date($record->check_out_date) ?? ''), ' →'),
                'Status' => $record->status,
                'Total' => $money($record->total_amount),
                'Invoice status' => $record->ghl_invoice_status,
                'Booked via' => $record->source,
                'Created' => $record->created_at?->toDateTimeString(),
            ],
            'campsite_transaction' => [
                'Campsite' => $record->product?->name,
                'Amount' => $money($record->amount),
                'Status' => $record->status,
                'Booking' => $record->booking_id,
            ],
            'product_transaction' => [
                'Invoice' => $record->ghl_invoice_number,
                'Total' => $money($record->amount),
                'Status' => $record->status,
                'Created' => $record->created_at?->toDateTimeString(),
            ],
            'customer' => [
                'Name' => $record->name,
                'Email' => $record->email,
                'Phone' => $record->phone,
                'Came from' => $record->source,
                'Created' => $record->created_at?->toDateTimeString(),
            ],
            'campsite' => [
                'Campsite' => $record->product?->name,
                'Variant' => $record->name,
                'Lead Connector id' => $record->ghl_id,
            ],
            default => [],
        };

        return collect($rows)
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->map(fn ($value, $label) => ['label' => $label, 'value' => (string) $value])
            ->values()
            ->all();
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

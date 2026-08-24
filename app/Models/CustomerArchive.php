<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A snapshot of a Customer at the moment it was archived (see
 * CustomerService::archiveCustomer()) — the sole source of truth for the
 * admin "Customer Archive" page and for email-based restore matching (see
 * CustomerService::findArchiveByEmail()/restoreFromArchive()). The
 * underlying Customer row itself is only ever soft-deleted, never removed —
 * this table exists to keep active/archived customers visibly and
 * queryably separated, not to physically relocate the row.
 */
class CustomerArchive extends Model
{
    use HasUlids;

    protected $fillable = [
        'engage_organization_location_id',
        'customer_id',
        'name',
        'email',
        'phone',
        'address',
        'ghl_contact_id',
        'ghl_sync_status',
        'ghl_last_synced_at',
        'created_by',
        'original_created_at',
        'archived_at',
        'archived_reason',
    ];

    protected function casts(): array
    {
        return [
            'address' => 'json',
            'ghl_last_synced_at' => 'datetime',
            'original_created_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /** The original Customer row (soft-deleted, findable via withTrashed()), if it still exists. */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(EngageCustomer::class)->withTrashed();
    }
}

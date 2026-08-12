<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class GhlSyncLog extends Model
{
    use HasUlids;

    protected $fillable = [
        'engage_organization_location_id',
        'status',
        'triggered_by',
        'total_contacts_pulled',
        'total_categories_pulled',
        'total_service_categories_pulled',
        'total_products_pulled',
        'total_services_pulled',
        'total_rentals_pulled',
        'total_paid_bookings_pulled',
        'total_paid_invoices_pulled',
        'started_at',
        'completed_at',
        'duration_ms',
        'error_message',
        'phase_errors',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'phase_errors' => 'array',
        ];
    }
}

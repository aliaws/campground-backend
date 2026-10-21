<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One organization's measured usage for one UTC date. `metrics` maps a
 * metric key to its quantity in the metric's unit (GB for storage); a null
 * value means it could not be measured that day.
 */
class OrganizationUsageSnapshot extends Model
{
    use HasUlids;

    public const STATUS_COMPLETE = 'complete';

    public const STATUS_PARTIAL = 'partial';

    protected $fillable = [
        'engage_organization_location_id', 'snapshot_date', 'metrics', 'metric_status',
        'storage', 'status', 'errors', 'measured_at', 'usage_measurement_run_id',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date:Y-m-d',
            'metrics' => 'array',
            'metric_status' => 'array',
            'storage' => 'array',
            'errors' => 'array',
            'measured_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(EngageOrganizationLocation::class, 'engage_organization_location_id');
    }
}

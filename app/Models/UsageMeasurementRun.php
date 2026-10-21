<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One run of the usage measurement (nightly, Measure Now, or by hand). */
class UsageMeasurementRun extends Model
{
    use HasUlids;

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETE = 'complete';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'snapshot_date', 'triggered_by', 'user_id', 'status', 'organization_count',
        'started_at', 'finished_at', 'duration_ms', 'summary', 'errors',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date:Y-m-d',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'summary' => 'array',
            'errors' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

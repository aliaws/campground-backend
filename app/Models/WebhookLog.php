<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class WebhookLog extends Model
{
    use HasUlids;

    protected $fillable = [
        'source',
        'event_type',
        'payload',
        'status',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'json',
        ];
    }
}

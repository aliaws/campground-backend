<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EngageToken extends Model
{
    use HasUlids;

    protected $fillable = [
        'tenant_id',
        'engage_setting_id',
        'location_id',
        'user_id',
        'company_id',
        'authorization_code',
        'access_token',
        'refresh_token',
        'token_expiry',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
        'authorization_code',
    ];

    protected function casts(): array
    {
        return [
            'token_expiry' => 'datetime',
        ];
    }

    public function setting(): BelongsTo
    {
        return $this->belongsTo(EngageSetting::class, 'engage_setting_id');
    }

    public function isTokenExpired(): bool
    {
        if (! $this->token_expiry) {
            return true;
        }

        return $this->token_expiry->isPast();
    }
}

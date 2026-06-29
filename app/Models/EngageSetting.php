<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class EngageSetting extends Model
{
    use HasUlids;

    protected $fillable = [
        'tenant_id',
        'location_id',
        'client_id',
        'client_secret',
        'api_version',
        'api_base_url',
        'user_id',
        'company_id',
        'api_key',
        'authorization_code',
        'access_token',
        'refresh_token',
        'token_expiry',
    ];

    protected $hidden = [
        'client_secret',
        'api_key',
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

    public function isTokenExpired(): bool
    {
        if (!$this->token_expiry) {
            return true;
        }

        return $this->token_expiry->isPast();
    }
}

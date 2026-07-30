<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Engage OAuth app credentials.
 *
 * The DB column is still named `tenant_id` (historical; migrations/seeders unchanged).
 * Application code uses {@see $oauth_state_key} only.
 */
class EngageSetting extends Model
{
    use HasUlids;

    /** Physical column name for the OAuth state key (do not use in app code). */
    public const OAUTH_STATE_COLUMN = 'tenant_id';

    protected $fillable = [
        'oauth_state_key',
        // Seeders still mass-assign the historical column key; app code uses oauth_state_key.
        'tenant_id',
        'client_id',
        'client_secret',
        'api_version',
        'api_base_url',
        'timezone',
        'scopes',
    ];

    protected $hidden = [
        'client_secret',
    ];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
        ];
    }

    public function token(): HasOne
    {
        return $this->hasOne(EngageToken::class, 'engage_setting_id');
    }

    public function getOauthStateKeyAttribute(): ?string
    {
        return $this->attributes[self::OAUTH_STATE_COLUMN] ?? null;
    }

    public function setOauthStateKeyAttribute(?string $value): void
    {
        $this->attributes[self::OAUTH_STATE_COLUMN] = $value;
    }

    public function scopeWhereOauthStateKey($query, string $key)
    {
        return $query->where(self::OAUTH_STATE_COLUMN, $key);
    }
}

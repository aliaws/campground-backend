<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class EngageSetting extends Model
{
    use HasUlids;

    protected $fillable = [
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
}

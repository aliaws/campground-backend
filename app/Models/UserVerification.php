<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserVerification extends Model
{
    use HasUlids;

    public const TYPE_EMAIL_VERIFICATION = 'email_verification';

    public const TYPE_PASSWORD_RESET = 'password_reset';

    public const TYPE_OAUTH = 'oauth';

    protected $fillable = [
        'user_id',
        'type',
        'jti',
        'code_hash',
        'attempts',
        'expires_at',
        'verified_at',
        'consumed_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'consumed_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }
}

<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'role', 'tenant_id'])]
#[Hidden([
    'password',
    'remember_token',
    'guest_action_token_hash',
    'guest_verification_code_hash',
])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isStaff(): bool
    {
        return $this->role === 'staff';
    }

    public function isCashier(): bool
    {
        return $this->role === 'cashier';
    }

    public function isGuest(): bool
    {
        return $this->role === 'guest';
    }

    public function isActiveGuestAccount(): bool
    {
        return $this->role === 'guest' && $this->guest_status === 'active';
    }

    /**
     * Point-in-time "Created By" label for Customer/Booking audit tracking —
     * snapshotted as a plain string at creation, not recomputed later, so a
     * name change afterward doesn't rewrite history. Only 3 buckets exist:
     * Admin, Staff (also covers cashier — there's no separate Cashier bucket),
     * and Customer (the public/guest booking widget, no authenticated user).
     */
    public static function createdByLabel(?self $user, string $fallbackName): string
    {
        if (! $user) {
            return "Customer - {$fallbackName}";
        }

        $bucket = $user->role === 'admin' ? 'Admin' : 'Staff';

        return "{$bucket} - {$user->name}";
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'guest_verified_at' => 'datetime',
            'guest_registered_at' => 'datetime',
            'guest_action_expires_at' => 'datetime',
        ];
    }
}

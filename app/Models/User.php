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
    'customer_account_token_hash',
    'customer_verification_code_hash',
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

    public function isCustomer(): bool
    {
        return $this->role === 'customer';
    }

    public function isActiveCustomerAccount(): bool
    {
        return $this->role === 'customer' && $this->customer_status === 'active';
    }

    /**
     * Point-in-time "Created By" label for Customer/Booking audit tracking —
     * snapshotted as a plain string at creation, not recomputed later, so a
     * name change afterward doesn't rewrite history. Only 3 buckets exist:
     * Admin, Staff (also covers cashier — there's no separate Cashier bucket),
     * and Customer (the public-facing booking widget, no authenticated user).
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
            'customer_verified_at' => 'datetime',
            'customer_registered_at' => 'datetime',
            'customer_account_expires_at' => 'datetime',
        ];
    }
}

<?php

namespace App\Models;

use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'address',
        'ghl_contact_id',
        'ghl_sync_status',
        'ghl_last_synced_at',
        'tenant_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'address' => 'json',
            'ghl_last_synced_at' => 'datetime',
        ];
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** The guest portal login linked to this customer, if one has been created (see GuestAccountService::ensureGuestAccount()). */
    public function guestUser(): HasOne
    {
        return $this->hasOne(User::class, 'customer_id');
    }
}

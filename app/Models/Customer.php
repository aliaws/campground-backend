<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasUlids, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'address',
        'ghl_contact_id',
        'tenant_id',
    ];

    protected function casts(): array
    {
        return [
            'address' => 'json',
        ];
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}

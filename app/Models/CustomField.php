<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class CustomField extends Model
{
    use HasUlids;

    protected $fillable = [
        'entity_type',
        'field_name',
        'field_type',
        'tenant_id',
    ];
}

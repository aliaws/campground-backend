<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class EngageMenuItem extends Model
{
    use HasUlids;

    protected $table = 'engage_menu_items';

    protected $fillable = ['key', 'parent_key', 'label', 'default_label', 'sort_order', 'is_visible', 'is_locked'];

    protected function casts(): array
    {
        return [
            'is_visible' => 'boolean',
            'is_locked' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}

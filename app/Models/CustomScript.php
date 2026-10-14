<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * A code snippet (HTML with <script> tags, or plain JavaScript) the
 * superadmin adds to the customer website, the staff portal, or both.
 * Platform-global. The frontend inserts it in the page head, at the start
 * of the body, or at the end of the body.
 */
class CustomScript extends Model
{
    use HasUlids;

    public const PLACEMENT_HEAD = 'head';

    public const PLACEMENT_BODY_START = 'body_start';

    public const PLACEMENT_BODY_END = 'body_end';

    public const PLACEMENTS = [self::PLACEMENT_HEAD, self::PLACEMENT_BODY_START, self::PLACEMENT_BODY_END];

    public const TARGET_CUSTOMER = 'customer';

    public const TARGET_STAFF = 'staff';

    public const TARGET_BOTH = 'both';

    public const TARGETS = [self::TARGET_CUSTOMER, self::TARGET_STAFF, self::TARGET_BOTH];

    /** The two places a script can run; `both` covers each of them. */
    public const WORKSPACES = [self::TARGET_CUSTOMER, self::TARGET_STAFF];

    protected $fillable = ['name', 'code', 'placement', 'target', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** Active scripts that run in the given workspace (customer|staff), in display order. */
    public function scopeActiveFor(Builder $query, string $workspace): Builder
    {
        return $query->where('is_active', true)
            ->whereIn('target', [$workspace, self::TARGET_BOTH])
            ->orderBy('sort_order')
            ->orderBy('created_at');
    }

    public static function cacheKey(string $workspace): string
    {
        return "custom-scripts:{$workspace}";
    }

    /** Called after every write: the public read is cached forever per workspace. */
    public static function forgetCache(): void
    {
        foreach (self::WORKSPACES as $workspace) {
            Cache::forget(self::cacheKey($workspace));
        }
    }
}

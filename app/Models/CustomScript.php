<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Cache;

/**
 * A code snippet (HTML with <script> tags, or plain JavaScript) the
 * superadmin adds for every organization or only selected ones, and in each
 * of them to the customer website, the staff portal, or both. The frontend
 * inserts it in the page head, at the start of the body, or at the end of
 * the body.
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

    /** The organizations this script is limited to; none means every organization. */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(
            EngageOrganizationLocation::class,
            'custom_script_organizations',
            'custom_script_id',
            'engage_organization_location_id',
        );
    }

    /**
     * Active scripts that run in the given workspace (customer|staff) for the
     * given organization, in display order. Without an organization (e.g. a
     * superadmin page) only the scripts meant for every organization match.
     */
    public function scopeActiveFor(Builder $query, string $workspace, ?string $organizationId = null): Builder
    {
        return $query->where('is_active', true)
            ->whereIn('target', [$workspace, self::TARGET_BOTH])
            ->where(function (Builder $q) use ($organizationId) {
                $q->whereDoesntHave('organizations');
                if ($organizationId) {
                    $q->orWhereHas('organizations', fn (Builder $o) => $o->whereKey($organizationId));
                }
            })
            ->orderBy('sort_order')
            ->orderBy('created_at');
    }

    /**
     * Cache key for one workspace + organization. The version number is
     * bumped on every write, which retires every cached combination at once.
     */
    public static function cacheKey(string $workspace, ?string $organizationId): string
    {
        $version = (int) Cache::get('custom-scripts:version', 1);

        return "custom-scripts:v{$version}:{$workspace}:".($organizationId ?: 'all');
    }

    /** Called after every write. */
    public static function forgetCache(): void
    {
        Cache::forever('custom-scripts:version', (int) Cache::get('custom-scripts:version', 1) + 1);
    }
}

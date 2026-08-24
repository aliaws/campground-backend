<?php

namespace App\Http\Resources;

use App\Models\SiteMap;
use Illuminate\Http\Request;

/**
 * Public map card — extends SiteMapResource (same key set, no duplicated
 * logic) with organization attribution. Once the public site map
 * aggregates every organization's own map together (2026-08-19), the
 * customer-facing map switcher needs a way to tell two maps apart when
 * they happen to belong to different organizations. Deliberately a
 * subclass rather than editing SiteMapResource directly — the
 * authenticated staff SiteMapController (POS map builder) also uses that
 * class and must stay completely unaffected.
 */
class PublicSiteMapResource extends SiteMapResource
{
    public function toArray(Request $request): array
    {
        /** @var SiteMap $map */
        $map = $this->resource;

        return array_merge(parent::toArray($request), [
            'organization_id' => $map->engage_organization_location_id,
            'organization_name' => $this->whenLoaded('organizationLocation', fn () => $map->organizationLocation?->name),
        ]);
    }
}

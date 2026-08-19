<?php

namespace App\Services;

use App\Models\EngageOrganizationLocation;

/** Resolves the Engage organization location for unauthenticated/public requests. */
class OrganizationLocationResolver
{
    public static function resolveDefaultLocationId(): string
    {
        $id = EngageOrganizationLocation::query()->where('is_default', true)->value('id')
            ?? EngageOrganizationLocation::query()->orderBy('created_at')->value('id');

        if (! is_string($id) || $id === '') {
            throw new \RuntimeException('No engage_organization_locations row configured.');
        }

        return $id;
    }

    /**
     * Resolves an inbound GHL webhook to the correct organization by
     * matching the GHL sub-account's own location id (in the webhook
     * payload) against engage_organization_locations.engage_location_id —
     * critical once more than one organization is connected, since every
     * webhook otherwise landed on whichever location happened to be
     * flagged "default," misattributing (and leaking) another
     * organization's contact/opportunity data into it. Falls back to
     * resolveDefaultLocationId() only when the payload carries no
     * identifiable location (or it doesn't match a connected one) — the
     * same single-tenant behavior this already had, preserved for that case.
     */
    public static function resolveFromGhlLocationId(?string $ghlLocationId): string
    {
        if (is_string($ghlLocationId) && $ghlLocationId !== '') {
            $id = EngageOrganizationLocation::query()
                ->where('engage_location_id', $ghlLocationId)
                ->value('id');

            if (is_string($id) && $id !== '') {
                return $id;
            }
        }

        return self::resolveDefaultLocationId();
    }
}

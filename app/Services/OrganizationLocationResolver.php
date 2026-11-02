<?php

namespace App\Services;

use App\Models\EngageOrganizationLocation;

/** Resolves the Engage organization location for unauthenticated/public requests. */
class OrganizationLocationResolver
{
    /**
     * The organization this deployment runs for: the default one when it is
     * active, otherwise the oldest active one. Blocked and uninstalled
     * organizations are only returned when no organization is active at all
     * (one deployment = one active organization; others get their own domain).
     */
    public static function resolveDefaultLocationId(): string
    {
        $active = fn () => EngageOrganizationLocation::query()->active();

        $id = $active()->where('is_default', true)->value('id')
            ?? $active()->orderBy('created_at')->value('id')
            ?? EngageOrganizationLocation::query()->where('is_default', true)->value('id')
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

    /**
     * $locationId when it belongs to an active organization, otherwise the
     * active default (resolveDefaultLocationId()). Used by GhlClient so a
     * Lead Connector call never runs with a blocked organization's token.
     */
    public static function activeOrDefault(?string $locationId): string
    {
        if (is_string($locationId) && $locationId !== ''
            && EngageOrganizationLocation::query()->active()->whereKey($locationId)->exists()) {
            return $locationId;
        }

        return self::resolveDefaultLocationId();
    }

    /** True when $ghlLocationId (a Lead Connector location id) belongs to a blocked or uninstalled organization. */
    public static function isInactiveGhlLocation(?string $ghlLocationId): bool
    {
        if (! is_string($ghlLocationId) || $ghlLocationId === '') {
            return false;
        }

        $status = EngageOrganizationLocation::query()->where('engage_location_id', $ghlLocationId)->value('status');

        return $status !== null && $status !== EngageOrganizationLocation::STATUS_ACTIVE;
    }
}

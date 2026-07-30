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
}

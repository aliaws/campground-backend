<?php

namespace App\Services;

/**
 * Bound as a singleton — holds which organization/location GhlClient
 * should resolve Lead Connector credentials for during the current unit of
 * work. Exists specifically for GhlFullSyncService::pullAll() /
 * GhlDailySync, which loop over *multiple* locations within one PHP
 * process (no single "current authenticated user" to derive it from the
 * way every normal HTTP request can) — pullAll() calls set() before
 * processing each location and resets it afterward, so GhlClient always
 * resolves the correct location's own access token, never a stale one left
 * over from a previous iteration.
 */
class GhlLocationContext
{
    private ?string $locationId = null;

    public function set(?string $locationId): void
    {
        $this->locationId = $locationId;
    }

    public function get(): ?string
    {
        return $this->locationId;
    }
}

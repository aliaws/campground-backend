<?php

namespace App\Console\Commands;

use App\Models\EngageToken;
use App\Services\GhlAuthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Restores expired (or soon-expiring) location access tokens via refresh_token
 * and rewrites the Redis cache keyed by location_id.
 */
class RefreshExpiredEngageTokens extends Command
{
    protected $signature = 'engage:refresh-expired-tokens
                            {--hours=6 : Refresh when expiry is within this many hours (or already past)}';

    protected $description = 'Refresh expired/soon-expiring Engage location tokens and update Redis cache';

    public function handle(GhlAuthService $ghlAuthService): int
    {
        $hours = max(0, (int) $this->option('hours'));
        $cutoff = now()->addHours($hours);

        $tokens = EngageToken::query()
            ->with('setting')
            ->whereNotNull('refresh_token')
            ->where('refresh_token', '!=', '')
            ->where(function ($query) use ($cutoff) {
                $query->whereNull('token_expiry')
                    ->orWhere('token_expiry', '<=', $cutoff);
            })
            ->get();

        if ($tokens->isEmpty()) {
            $this->info('No location tokens need refresh.');

            return self::SUCCESS;
        }

        $refreshed = 0;
        $failed = 0;

        foreach ($tokens as $token) {
            $setting = $token->setting;
            $locationKey = $token->location_id ?: $token->id;

            if (! $setting || ! $setting->client_id || ! $setting->client_secret) {
                $this->warn("Skip {$locationKey}: missing engage credentials.");
                $failed++;

                continue;
            }

            try {
                $fresh = $ghlAuthService->refreshAccessToken($setting, $token);
                $refreshed++;
                $this->info(sprintf(
                    'Refreshed location_id=%s expiry=%s',
                    $fresh->location_id ?? 'null',
                    $fresh->token_expiry?->toIso8601String() ?? 'null',
                ));
            } catch (\Throwable $e) {
                $failed++;
                $this->error("Failed location_id={$locationKey}: {$e->getMessage()}");
                Log::error('Scheduled Engage token refresh failed', [
                    'location_id' => $token->location_id,
                    'engage_token_id' => $token->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Done. refreshed={$refreshed} failed={$failed}");

        return $failed > 0 && $refreshed === 0 ? self::FAILURE : self::SUCCESS;
    }
}

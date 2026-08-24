<?php

namespace App\Providers;

use App\Auth\JwtGuard;
use App\Integrations\GHL\GhlClient;
use App\Integrations\GHL\GhlWebhookHandler;
use App\Services\GhlLocationContext;
use App\Services\GhlService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(GhlLocationContext::class);

        $this->app->singleton(GhlClient::class, function ($app) {
            return new GhlClient($app->make(GhlLocationContext::class));
        });

        $this->app->singleton(GhlWebhookHandler::class, function ($app) {
            return new GhlWebhookHandler($app->make(GhlService::class));
        });
    }

    public function boot(): void
    {
        Auth::extend('jwt', function ($app, string $name, array $config) {
            return new JwtGuard(
                Auth::createUserProvider($config['provider']),
            );
        });

        RateLimiter::for('customer-browse', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
        RateLimiter::for('customer-booking', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));

        RateLimiter::for('customer-verify', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        RateLimiter::for('customer-register', function (Request $request) {
            $email = strtolower((string) $request->input('email'));

            return Limit::perHour(5)->by($request->ip().'|'.$email);
        });

        RateLimiter::for('customer-resend-verification', function (Request $request) {
            $email = strtolower((string) $request->input('email'));

            return Limit::perHour(5)->by($request->ip().'|'.$email);
        });

        RateLimiter::for('customer-forgot-password', function (Request $request) {
            $email = strtolower((string) $request->input('email', $request->ip()));

            return Limit::perHour(3)->by($request->ip().'|'.$email);
        });

        RateLimiter::for('customer-change-password', function (Request $request) {
            $id = $request->user()?->id ?? $request->ip();

            return Limit::perMinute(10)->by((string) $id);
        });

        RateLimiter::for('staff-forgot-password', function (Request $request) {
            $email = strtolower((string) $request->input('email', $request->ip()));

            return Limit::perHour(5)->by($request->ip().'|'.$email);
        });

        RateLimiter::for('staff-change-password', function (Request $request) {
            $id = $request->user()?->id ?? $request->ip();

            return Limit::perMinute(10)->by((string) $id);
        });

        // Configurable via .env — see config/organization.php's rate_limits block.
        RateLimiter::for('organization-register', fn (Request $request) => Limit::perHour(
            (int) config('organization.rate_limits.register_per_hour')
        )->by($request->ip()));

        RateLimiter::for('organization-complete', fn (Request $request) => Limit::perHour(
            (int) config('organization.rate_limits.complete_per_hour')
        )->by($request->ip()));

        RateLimiter::for('organization-resend-verification', function (Request $request) {
            $locationId = (string) $request->input('location_id');

            return Limit::perHour((int) config('organization.rate_limits.resend_per_hour'))
                ->by($request->ip().'|'.$locationId);
        });

        RateLimiter::for('organization-verify', fn (Request $request) => Limit::perMinute(
            (int) config('organization.rate_limits.verify_per_minute')
        )->by($request->ip()));
    }
}

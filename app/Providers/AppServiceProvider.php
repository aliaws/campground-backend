<?php

namespace App\Providers;

use App\Integrations\GHL\GhlClient;
use App\Integrations\GHL\GhlWebhookHandler;
use App\Services\GhlService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(GhlClient::class, function () {
            return new GhlClient;
        });

        $this->app->singleton(GhlWebhookHandler::class, function ($app) {
            return new GhlWebhookHandler($app->make(GhlService::class));
        });
    }

    public function boot(): void
    {
        RateLimiter::for('guest-browse', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
        RateLimiter::for('guest-booking', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));

        RateLimiter::for('guest-verify', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        RateLimiter::for('guest-resend-verification', function (Request $request) {
            $email = strtolower((string) $request->input('email'));

            return Limit::perHour(5)->by($request->ip().'|'.$email);
        });

        RateLimiter::for('guest-forgot-password', function (Request $request) {
            $email = strtolower((string) $request->input('email', $request->ip()));

            return Limit::perHour(3)->by($request->ip().'|'.$email);
        });

        RateLimiter::for('guest-change-password', function (Request $request) {
            $id = $request->user()?->id ?? $request->ip();

            return Limit::perMinute(10)->by((string) $id);
        });
    }
}

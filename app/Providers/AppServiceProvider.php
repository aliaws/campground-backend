<?php

namespace App\Providers;

use App\Integrations\GHL\GhlClient;
use App\Integrations\GHL\GhlWebhookHandler;
use App\Services\GhlService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(GhlClient::class, function () {
            return new GhlClient();
        });

        $this->app->singleton(GhlWebhookHandler::class, function ($app) {
            return new GhlWebhookHandler($app->make(GhlService::class));
        });
    }

    public function boot(): void
    {
        //
    }
}

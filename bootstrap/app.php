<?php

use App\Http\Middleware\RoleMiddleware;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule) {
        $schedule->command('engage:refresh-expired-tokens')
            ->everySixHours()
            ->withoutOverlapping();
    })
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role' => RoleMiddleware::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule) {
        // Requires one system crontab entry pointing at `artisan schedule:run`
        // (standard Laravel scheduler setup) — not present in this repo/env,
        // must be added by whoever manages the deployed server.
        $schedule->command('ghl:sync-all')
            ->daily()
            ->withoutOverlapping()
            ->onOneServer();
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();

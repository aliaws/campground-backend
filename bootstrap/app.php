<?php

use App\Http\Middleware\EnsureOrganizationNotBlocked;
use App\Http\Middleware\PermissionMiddleware;
use App\Http\Middleware\RoleMiddleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

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
            'permission' => PermissionMiddleware::class,
            'org.active' => EnsureOrganizationNotBlocked::class,
        ]);
        // This app is API-only — no `web` route group, no `login` named
        // route. Laravel's default guest-redirect logic calls route('login')
        // directly while building the AuthenticationException it's about to
        // throw, which throws its own RouteNotFoundException first and
        // surfaces as a 500 instead of a clean 401 whenever a request
        // doesn't set an Accept header that looks like JSON. Returning null
        // here skips the redirect attempt entirely so the real 401 (with
        // the exceptions->render() JSON body below) is what's returned.
        $middleware->redirectGuestsTo(fn () => null);
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
        // This app is API-only (no `web` route group, no `login` named
        // route) — Laravel's default unauthenticated-request handling tries
        // to redirect to `route('login')` whenever the request doesn't look
        // like it "expects JSON" (e.g. no explicit Accept header, which
        // plenty of real clients omit), which throws RouteNotFoundException
        // and surfaces as a 500 instead of a clean 401. Always render JSON.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        });
    })->create();

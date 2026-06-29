<?php

use App\Http\Controllers\Api\V1\AmenityController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\FeatureController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ReservationController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\TransactionController;
use App\Http\Controllers\Api\V1\WebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Auth
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    Route::get('/auth/me', [AuthController::class, 'me'])->middleware('auth:sanctum');

    // Webhooks (no auth - GHL calls these)
    Route::post('/webhooks/ghl', [WebhookController::class, 'ghl']);

    // GHL OAuth callback (no auth - browser redirect from GHL)
    Route::get('/settings/engage/callback', [SettingsController::class, 'handleCallback']);

    // Protected routes
    Route::middleware(['auth:sanctum'])->group(function () {
        // Products (unified - campsites + inventory)
        Route::get('/products', [ProductController::class, 'index']);
        Route::post('/products', [ProductController::class, 'store']);
        Route::get('/products/{product}', [ProductController::class, 'show']);
        Route::put('/products/{product}', [ProductController::class, 'update']);
        Route::delete('/products/{product}', [ProductController::class, 'destroy']);
        Route::post('/products/{product}/image', [ProductController::class, 'uploadImage']);

        // Product metadata
        Route::get('/products/{product}/prices', [ProductController::class, 'prices']);
        Route::post('/products/{product}/prices', [ProductController::class, 'storePrice']);
        Route::get('/products/{product}/variations', [ProductController::class, 'variations']);
        Route::post('/products/{product}/variations', [ProductController::class, 'storeVariation']);

        // Customers
        Route::get('/customers', [CustomerController::class, 'index']);
        Route::post('/customers', [CustomerController::class, 'store']);
        Route::get('/customers/{customer}', [CustomerController::class, 'show']);
        Route::put('/customers/{customer}', [CustomerController::class, 'update']);
        Route::delete('/customers/{customer}', [CustomerController::class, 'destroy']);

        // Reservations
        Route::get('/reservations', [ReservationController::class, 'index']);
        Route::post('/reservations', [ReservationController::class, 'store']);
        Route::get('/reservations/{reservation}', [ReservationController::class, 'show']);
        Route::patch('/reservations/{reservation}/status', [ReservationController::class, 'updateStatus']);

        // Transactions
        Route::get('/transactions', [TransactionController::class, 'index']);
        Route::post('/transactions', [TransactionController::class, 'store']);
        Route::get('/transactions/{transaction}', [TransactionController::class, 'show']);
        Route::patch('/transactions/{transaction}/payment-status', [TransactionController::class, 'updatePaymentStatus']);
        Route::get('/transactions/{transaction}/invoice', [TransactionController::class, 'invoice']);

        // Catalog
        Route::get('/categories', [CategoryController::class, 'index']);
        Route::post('/categories', [CategoryController::class, 'store']);
        Route::get('/amenities', [AmenityController::class, 'index']);
        Route::post('/amenities', [AmenityController::class, 'store']);
        Route::get('/features', [FeatureController::class, 'index']);
        Route::post('/features', [FeatureController::class, 'store']);

        // Settings
        Route::get('/settings/engage', [SettingsController::class, 'getEngage']);
        Route::post('/settings/engage', [SettingsController::class, 'storeEngage']);
        Route::get('/settings/engage/authorize', [SettingsController::class, 'getAuthorizeUrl']);
        Route::post('/settings/engage/refresh-token', [SettingsController::class, 'refreshToken']);
        Route::get('/settings/engage/tokens', [SettingsController::class, 'getTokens']);
        Route::post('/settings/engage/tokens', [SettingsController::class, 'saveTokens']);
        Route::get('/settings/countries', [SettingsController::class, 'getCountries']);
        Route::get('/settings/custom-fields', [SettingsController::class, 'getCustomFields']);
        Route::post('/settings/custom-fields', [SettingsController::class, 'storeCustomField']);
    });

});

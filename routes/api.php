<?php

use App\Http\Controllers\Api\V1\AmenityController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BookingController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\FeatureController;
use App\Http\Controllers\Api\V1\Guest\GuestPasswordController;
use App\Http\Controllers\Api\V1\Guest\GuestPortalController;
use App\Http\Controllers\Api\V1\Guest\GuestVerificationController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\Public\PublicBookingController;
use App\Http\Controllers\Api\V1\Public\PublicCategoryController;
use App\Http\Controllers\Api\V1\Public\PublicServiceController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\ServiceController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\TransactionController;
use App\Http\Controllers\Api\V1\WebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Auth — login/register open; logout/me for any authenticated role (staff or guest)
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    Route::get('/auth/me', [AuthController::class, 'me'])->middleware('auth:sanctum');

    // Webhooks (no auth - GHL calls these)
    Route::post('/webhooks/ghl', [WebhookController::class, 'ghl']);

    // GHL OAuth callback (no auth - browser redirect from GHL)
    Route::get('/settings/engage/callback', [SettingsController::class, 'handleCallback']);

    // Public guest booking (no auth) — customer-facing booking site
    Route::prefix('public')->group(function () {
        Route::middleware('throttle:guest-browse')->group(function () {
            Route::get('/services', [PublicServiceController::class, 'index']);
            Route::get('/services/variant/{id}', [PublicServiceController::class, 'variant']);
            Route::get('/services/{product}', [PublicServiceController::class, 'show']);
            Route::get('/categories', [PublicCategoryController::class, 'index']);
            Route::post('/bookings/quote', [PublicBookingController::class, 'quote']);
            Route::get('/bookings/{booking}', [PublicBookingController::class, 'show']);
        });
        Route::middleware('throttle:guest-booking')->group(function () {
            Route::post('/bookings', [PublicBookingController::class, 'store']);
        });
    });

    // Guest verification / password (unauthenticated) + portal (role:guest)
    Route::prefix('guest')->group(function () {
        Route::post('/verify-code', [GuestVerificationController::class, 'verifyCode'])
            ->middleware('throttle:guest-verify');
        Route::post('/resend-verification', [GuestVerificationController::class, 'resend'])
            ->middleware('throttle:guest-resend-verification');
        Route::post('/create-password', [GuestPasswordController::class, 'createPassword'])
            ->middleware('throttle:guest-verify');
        Route::post('/forgot-password', [GuestPasswordController::class, 'forgotPassword'])
            ->middleware('throttle:guest-forgot-password');
        Route::post('/reset-password', [GuestPasswordController::class, 'resetPassword'])
            ->middleware('throttle:guest-forgot-password');

        Route::middleware(['auth:sanctum', 'role:guest'])->group(function () {
            Route::post('/change-password', [GuestPasswordController::class, 'changePassword'])
                ->middleware('throttle:guest-change-password');

            Route::get('/bookings', [GuestPortalController::class, 'bookings']);
            Route::get('/bookings/{booking}', [GuestPortalController::class, 'bookingShow']);
            Route::post('/bookings/{booking}/cancel', [GuestPortalController::class, 'cancelBooking']);
            Route::get('/bookings/{booking}/invoice', [GuestPortalController::class, 'invoice']);
            Route::put('/profile', [GuestPortalController::class, 'updateProfile']);
        });
    });

    // Staff-protected routes
    Route::middleware(['auth:sanctum', 'role:admin,staff,cashier'])->group(function () {
        // Products (unified - campsites + inventory)
        Route::get('/products', [ProductController::class, 'index']);
        Route::post('/products', [ProductController::class, 'store']);
        Route::get('/products/{product}', [ProductController::class, 'show']);
        Route::put('/products/{product}', [ProductController::class, 'update']);
        Route::delete('/products/{product}', [ProductController::class, 'destroy']);
        Route::post('/products/{product}/image', [ProductController::class, 'uploadImage']);

        // Product categories
        Route::post('/products/{product}/categories', [ProductController::class, 'attachCategories']);

        // Product GHL sync
        Route::post('/products/{product}/sync-ghl', [ProductController::class, 'syncToGhl']);
        Route::post('/products/{product}/pull-ghl', [ProductController::class, 'pullFromGhl']);
        Route::post('/products/bulk-sync-ghl', [ProductController::class, 'bulkSync']);
        Route::post('/products/bulk-pull-ghl', [ProductController::class, 'bulkPull']);

        // Customers
        Route::get('/customers', [CustomerController::class, 'index']);
        Route::post('/customers', [CustomerController::class, 'store']);
        Route::get('/customers/{customer}', [CustomerController::class, 'show']);
        Route::put('/customers/{customer}', [CustomerController::class, 'update']);
        Route::post('/customers/{customer}/sync-ghl', [CustomerController::class, 'syncToGhl']);
        Route::post('/customers/bulk-sync-ghl', [CustomerController::class, 'bulkSync']);
        Route::post('/customers/bulk-pull-ghl', [CustomerController::class, 'bulkPull']);
        Route::delete('/customers/{customer}', [CustomerController::class, 'destroy']);

        // Services storefront (bookable SERVICE products, GHL Rentals style)
        Route::get('/services', [ServiceController::class, 'index']);
        Route::post('/services/pull-ghl', [ServiceController::class, 'pullFromGhl']);
        Route::get('/services/{product}', [ServiceController::class, 'show']);

        // Bookings
        Route::get('/bookings', [BookingController::class, 'index']);
        Route::post('/bookings/quote', [BookingController::class, 'quote']);
        Route::post('/bookings', [BookingController::class, 'store']);
        Route::get('/bookings/{booking}', [BookingController::class, 'show']);
        Route::get('/bookings/{booking}/invoice', [BookingController::class, 'invoice']);
        Route::patch('/bookings/{booking}/status', [BookingController::class, 'updateStatus']);
        Route::patch('/bookings/{booking}/check-in-out', [BookingController::class, 'updateCheckInOut']);
        Route::post('/bookings/{booking}/confirm', [BookingController::class, 'confirm']);
        Route::post('/bookings/{booking}/pay-cash', [BookingController::class, 'payCash']);

        // Reports
        Route::get('/reports/summary', [ReportController::class, 'summary']);

        // Transactions
        Route::get('/transactions', [TransactionController::class, 'index']);
        Route::post('/transactions', [TransactionController::class, 'store']);
        Route::get('/transactions/{transaction}', [TransactionController::class, 'show']);
        Route::patch('/transactions/{transaction}/payment-status', [TransactionController::class, 'updatePaymentStatus']);
        Route::get('/transactions/{transaction}/invoice', [TransactionController::class, 'invoice']);

        // Categories
        Route::get('/categories', [CategoryController::class, 'index']);
        Route::post('/categories', [CategoryController::class, 'store']);
        Route::get('/categories/{category}', [CategoryController::class, 'show']);
        Route::put('/categories/{category}', [CategoryController::class, 'update']);
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);
        Route::post('/categories/{category}/sync-ghl', [CategoryController::class, 'syncToGhl']);
        Route::post('/categories/bulk-sync-ghl', [CategoryController::class, 'bulkSync']);

        // Amenities
        Route::get('/amenities', [AmenityController::class, 'index']);
        Route::post('/amenities', [AmenityController::class, 'store']);
        Route::put('/amenities/{amenity}', [AmenityController::class, 'update']);
        Route::delete('/amenities/{amenity}', [AmenityController::class, 'destroy']);

        // Features
        Route::get('/features', [FeatureController::class, 'index']);
        Route::post('/features', [FeatureController::class, 'store']);
        Route::put('/features/{feature}', [FeatureController::class, 'update']);
        Route::delete('/features/{feature}', [FeatureController::class, 'destroy']);

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

<?php

use App\Http\Controllers\Api\V1\AmenityController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BookingController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\Customer\CustomerPasswordController;
use App\Http\Controllers\Api\V1\Customer\CustomerPortalController;
use App\Http\Controllers\Api\V1\Customer\CustomerVerificationController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\FeatureController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ProductTransactionController;
use App\Http\Controllers\Api\V1\Public\PublicBookingController;
use App\Http\Controllers\Api\V1\Public\PublicCategoryController;
use App\Http\Controllers\Api\V1\Public\PublicServiceCategoryController;
use App\Http\Controllers\Api\V1\Public\PublicServiceController;
use App\Http\Controllers\Api\V1\Public\PublicSiteMapController;
use App\Http\Controllers\Api\V1\RentalTransactionController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\ServiceCategoryController;
use App\Http\Controllers\Api\V1\ServiceController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\SiteMapController;
use App\Http\Controllers\Api\V1\SiteMapElementController;
use App\Http\Controllers\Api\V1\SiteMapIconTypeController;
use App\Http\Controllers\Api\V1\StaffController;
use App\Http\Controllers\Api\V1\WebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Auth — login/register open; logout/me for any authenticated role (staff or customer)
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('auth:api');
    Route::get('/auth/me', [AuthController::class, 'me'])->middleware('auth:api');
    Route::post('/auth/select-organization', [AuthController::class, 'selectOrganization'])->middleware('auth:api');

    // Webhooks (no auth - GHL calls these)
    Route::post('/webhooks/ghl', [WebhookController::class, 'ghl']);

    // GHL OAuth callback (no auth - browser redirect from GHL)
    Route::get('/settings/engage/callback', [SettingsController::class, 'handleCallback']);

    // Public customer booking (no auth) — customer-facing booking site
    Route::prefix('public')->group(function () {
        Route::middleware('throttle:customer-browse')->group(function () {
            Route::get('/services', [PublicServiceController::class, 'index']);
            Route::get('/services/variant/{id}', [PublicServiceController::class, 'variant']);
            Route::get('/services/{product}', [PublicServiceController::class, 'show']);
            Route::get('/categories', [PublicCategoryController::class, 'index']);
            Route::get('/service-categories', [PublicServiceCategoryController::class, 'index']);
            Route::post('/bookings/quote', [PublicBookingController::class, 'quote']);
            Route::get('/bookings/{booking}', [PublicBookingController::class, 'show']);

            // Interactive site map (customer/visitor viewer)
            Route::get('/site-maps', [PublicSiteMapController::class, 'index']);
            Route::get('/site-maps/{siteMap}', [PublicSiteMapController::class, 'show']);
        });
        Route::middleware('throttle:customer-booking')->group(function () {
            Route::post('/bookings', [PublicBookingController::class, 'store']);
        });
    });

    // Customer verification / password (unauthenticated) + portal (role:customer)
    Route::prefix('customer')->group(function () {
        Route::post('/register', [CustomerVerificationController::class, 'register'])
            ->middleware('throttle:customer-register');
        Route::post('/verify-code', [CustomerVerificationController::class, 'verifyCode'])
            ->middleware('throttle:customer-verify');
        Route::post('/resend-verification', [CustomerVerificationController::class, 'resend'])
            ->middleware('throttle:customer-resend-verification');
        Route::post('/create-password', [CustomerPasswordController::class, 'createPassword'])
            ->middleware('throttle:customer-verify');
        Route::post('/forgot-password', [CustomerPasswordController::class, 'forgotPassword'])
            ->middleware('throttle:customer-forgot-password');
        Route::post('/reset-password', [CustomerPasswordController::class, 'resetPassword'])
            ->middleware('throttle:customer-forgot-password');

        Route::middleware(['auth:api', 'role:customer'])->group(function () {
            Route::post('/change-password', [CustomerPasswordController::class, 'changePassword'])
                ->middleware('throttle:customer-change-password');

            Route::get('/bookings', [CustomerPortalController::class, 'bookings']);
            Route::get('/bookings/{booking}', [CustomerPortalController::class, 'bookingShow']);
            Route::post('/bookings/{booking}/cancel', [CustomerPortalController::class, 'cancelBooking']);
            Route::get('/bookings/{booking}/invoice', [CustomerPortalController::class, 'invoice']);
            Route::put('/profile', [CustomerPortalController::class, 'updateProfile']);
        });
    });

    // Staff-protected routes
    Route::middleware(['auth:api', 'role:admin,owner,staff,superadmin'])->group(function () {
        // Products (unified - campsites + inventory)
        Route::get('/products', [ProductController::class, 'index']);
        Route::post('/products', [ProductController::class, 'store']);
        // Must precede /products/{product} — otherwise implicit route-model
        // binding treats "lookup-by-sku" as a {product} id and 404s before
        // this route is ever matched.
        Route::get('/products/lookup-by-sku', [ProductController::class, 'lookupBySku']);
        Route::get('/products/{product}', [ProductController::class, 'show']);
        Route::put('/products/{product}', [ProductController::class, 'update']);
        Route::delete('/products/{product}', [ProductController::class, 'destroy']);
        Route::post('/products/{product}/image', [ProductController::class, 'uploadImage']);
        Route::get('/products/{product}/ghl-stock', [ProductController::class, 'ghlStock']);

        // Product categories
        Route::post('/products/{product}/categories', [ProductController::class, 'attachCategories']);

        // Product GHL sync
        Route::post('/products/{product}/sync-ghl', [ProductController::class, 'syncToGhl']);
        Route::post('/products/{product}/pull-ghl', [ProductController::class, 'pullFromGhl']);
        Route::post('/products/bulk-sync-ghl', [ProductController::class, 'bulkSync']);
        Route::post('/products/bulk-pull-ghl', [ProductController::class, 'bulkPull']);
        Route::post('/products/generate-skus', [ProductController::class, 'generateSkus']);

        // Customers
        Route::get('/customers', [CustomerController::class, 'index']);
        Route::post('/customers', [CustomerController::class, 'store']);
        // Must be registered before GET/POST /customers/{customer}* below —
        // otherwise "archived" would be swallowed by the {customer} wildcard
        // binding (same routing-order gotcha as /products/lookup-by-sku).
        Route::get('/customers/archived', [CustomerController::class, 'archived']);
        Route::post('/customers/archived/{archive}/restore', [CustomerController::class, 'restoreArchived']);
        Route::get('/customers/{customer}', [CustomerController::class, 'show']);
        Route::put('/customers/{customer}', [CustomerController::class, 'update']);
        Route::get('/customers/{customer}/deletion-preview', [CustomerController::class, 'deletionPreview']);
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

        // Transactions — sole source of truth split across two tables as of
        // the 2026-08-10 refactor (no more generic /transactions*).
        Route::get('/rental-transactions', [RentalTransactionController::class, 'index']);
        Route::get('/product-transactions', [ProductTransactionController::class, 'index']);
        Route::post('/product-transactions', [ProductTransactionController::class, 'store']);
        Route::get('/product-transactions/{productTransaction}', [ProductTransactionController::class, 'show']);
        Route::patch('/product-transactions/{productTransaction}/payment-status', [ProductTransactionController::class, 'updatePaymentStatus']);
        Route::get('/product-transactions/{productTransaction}/invoice', [ProductTransactionController::class, 'invoice']);

        // Categories
        Route::get('/categories', [CategoryController::class, 'index']);
        Route::post('/categories', [CategoryController::class, 'store']);
        Route::get('/categories/{category}', [CategoryController::class, 'show']);
        Route::put('/categories/{category}', [CategoryController::class, 'update']);
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);
        Route::post('/categories/{category}/sync-ghl', [CategoryController::class, 'syncToGhl']);
        Route::post('/categories/bulk-sync-ghl', [CategoryController::class, 'bulkSync']);
        Route::post('/categories/pull-ghl', [CategoryController::class, 'pullFromGhl']);

        // Service Categories (Services module — scoped to product_rentals, mirrors Categories above)
        Route::get('/service-categories', [ServiceCategoryController::class, 'index']);
        Route::post('/service-categories', [ServiceCategoryController::class, 'store']);
        Route::get('/service-categories/{serviceCategory}', [ServiceCategoryController::class, 'show']);
        Route::put('/service-categories/{serviceCategory}', [ServiceCategoryController::class, 'update']);
        Route::delete('/service-categories/{serviceCategory}', [ServiceCategoryController::class, 'destroy']);
        Route::post('/service-categories/pull-ghl', [ServiceCategoryController::class, 'pullFromGhl']);
        Route::post('/service-categories/{serviceCategory}/sync-ghl', [ServiceCategoryController::class, 'syncToGhl']);

        // Amenities (Services module — assigned to service listings via service_amenities)
        Route::get('/amenities', [AmenityController::class, 'index']);
        Route::post('/amenities', [AmenityController::class, 'store']);
        Route::put('/amenities/{amenity}', [AmenityController::class, 'update']);
        Route::post('/amenities/{amenity}/icon', [AmenityController::class, 'uploadIcon']);
        Route::delete('/amenities/{amenity}', [AmenityController::class, 'destroy']);

        // Features (Services module — assigned to service listings via service_features)
        Route::get('/features', [FeatureController::class, 'index']);
        Route::post('/features', [FeatureController::class, 'store']);
        Route::put('/features/{feature}', [FeatureController::class, 'update']);
        Route::post('/features/{feature}/icon', [FeatureController::class, 'uploadIcon']);
        Route::delete('/features/{feature}', [FeatureController::class, 'destroy']);

        // Staff management — admin-only: staff accounts are created here, not via public /auth/register
        Route::middleware('role:admin,owner,superadmin')->group(function () {
            Route::get('/staff', [StaffController::class, 'index']);
            Route::post('/staff', [StaffController::class, 'store']);
            Route::put('/staff/{staff}', [StaffController::class, 'update']);
            Route::delete('/staff/{staff}', [StaffController::class, 'destroy']);
        });

        // Site maps: viewing is open to any staff role, editing (the map builder) is admin-only
        Route::get('/site-maps', [SiteMapController::class, 'index']);
        Route::get('/site-maps/{siteMap}', [SiteMapController::class, 'show']);
        Route::get('/site-map-icon-types', [SiteMapIconTypeController::class, 'index']);
        Route::middleware('role:admin,owner,superadmin')->group(function () {
            Route::post('/site-maps', [SiteMapController::class, 'store']);
            Route::put('/site-maps/{siteMap}', [SiteMapController::class, 'update']);
            Route::delete('/site-maps/{siteMap}', [SiteMapController::class, 'destroy']);
            Route::post('/site-maps/{siteMap}/image', [SiteMapController::class, 'uploadImage']);
            Route::delete('/site-maps/{siteMap}/image', [SiteMapController::class, 'deleteImage']);
            Route::post('/site-maps/{siteMap}/elements', [SiteMapElementController::class, 'store']);
            Route::patch('/site-map-elements/{element}', [SiteMapElementController::class, 'update']);
            Route::delete('/site-map-elements/{element}', [SiteMapElementController::class, 'destroy']);
            Route::post('/site-map-icon-types', [SiteMapIconTypeController::class, 'store']);
            Route::delete('/site-map-icon-types/{iconType}', [SiteMapIconTypeController::class, 'destroy']);
        });

        // Settings
        Route::get('/settings/engage', [SettingsController::class, 'getEngage']);
        Route::post('/settings/engage', [SettingsController::class, 'storeEngage']);
        Route::get('/settings/engage/authorize', [SettingsController::class, 'getAuthorizeUrl']);
        Route::post('/settings/engage/refresh-token', [SettingsController::class, 'refreshToken']);
        Route::get('/settings/engage/tokens', [SettingsController::class, 'getTokens']);
        Route::post('/settings/engage/tokens', [SettingsController::class, 'saveTokens']);
        Route::post('/settings/engage/pull-ghl', [SettingsController::class, 'pullAllGhlData']);
        Route::get('/settings/engage/sync-log', [SettingsController::class, 'getLatestSyncLog']);
        Route::get('/settings/countries', [SettingsController::class, 'getCountries']);
        Route::get('/settings/custom-fields', [SettingsController::class, 'getCustomFields']);
        Route::post('/settings/custom-fields', [SettingsController::class, 'storeCustomField']);
    });

});

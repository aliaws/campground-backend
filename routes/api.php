<?php

use App\Http\Controllers\Api\V1\AmenityController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BookingController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\Customer\CustomerPasswordController;
use App\Http\Controllers\Api\V1\Customer\CustomerPortalController;
use App\Http\Controllers\Api\V1\Customer\CustomerVerificationController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\FeatureController;
use App\Http\Controllers\Api\V1\OrganizationProfileController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ProductTransactionController;
use App\Http\Controllers\Api\V1\Public\OrganizationRegistrationController;
use App\Http\Controllers\Api\V1\Public\PublicBookingController;
use App\Http\Controllers\Api\V1\Public\PublicCategoryController;
use App\Http\Controllers\Api\V1\Public\PublicCmsPageController;
use App\Http\Controllers\Api\V1\Public\PublicCountryController;
use App\Http\Controllers\Api\V1\Public\PublicEngageController;
use App\Http\Controllers\Api\V1\Public\PublicProductController;
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
use App\Http\Controllers\Api\V1\Superadmin\CmsPageController;
use App\Http\Controllers\Api\V1\Superadmin\EngageSettingsController;
use App\Http\Controllers\Api\V1\Superadmin\OrganizationController;
use App\Http\Controllers\Api\V1\Superadmin\OrganizationDataController;
use App\Http\Controllers\Api\V1\WebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Auth — login/register open; logout/me for any authenticated role (staff or customer)
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('auth:api');
    Route::get('/auth/me', [AuthController::class, 'me'])->middleware('auth:api');
    Route::post('/auth/select-organization', [AuthController::class, 'selectOrganization'])->middleware('auth:api');

    // Staff forgot/reset/change password (owner/admin/staff/superadmin) —
    // separate from the customer portal's identically-shaped /customer/*
    // flow below, since the reset link points at a different frontend page.
    Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])
        ->middleware('throttle:staff-forgot-password');
    Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])
        ->middleware('throttle:staff-forgot-password');
    Route::post('/auth/change-password', [AuthController::class, 'changePassword'])
        ->middleware(['auth:api', 'throttle:staff-change-password']);
    Route::post('/auth/avatar', [AuthController::class, 'uploadAvatar'])->middleware('auth:api');
    Route::delete('/auth/avatar', [AuthController::class, 'deleteAvatar'])->middleware('auth:api');

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
            Route::get('/shop/products', [PublicProductController::class, 'index']);
            Route::get('/pages/{slug}', [PublicCmsPageController::class, 'show']);
            Route::get('/engage/installation-url-template', [PublicEngageController::class, 'installationUrlTemplate']);
            Route::get('/countries', [PublicCountryController::class, 'index']);
            Route::post('/bookings/quote', [PublicBookingController::class, 'quote']);
            Route::get('/bookings/{booking}', [PublicBookingController::class, 'show']);

            // Interactive site map (customer/visitor viewer)
            Route::get('/site-maps', [PublicSiteMapController::class, 'index']);
            Route::get('/site-maps/{siteMap}', [PublicSiteMapController::class, 'show']);
        });
        Route::middleware('throttle:customer-booking')->group(function () {
            Route::post('/bookings', [PublicBookingController::class, 'store']);
        });

        // Self-service organization registration — see
        // OrganizationRegistrationService's doc comment for the full flow.
        // Rate limiters mirror the customer-auth ones directly above.
        Route::prefix('engage/organizations')->group(function () {
            Route::post('/register', [OrganizationRegistrationController::class, 'register'])
                ->middleware('throttle:organization-register');
            Route::post('/{organization}/complete', [OrganizationRegistrationController::class, 'complete'])
                ->middleware('throttle:organization-complete');
            Route::post('/resend-verification', [OrganizationRegistrationController::class, 'resend'])
                ->middleware('throttle:organization-resend-verification');
            Route::post('/verify-code', [OrganizationRegistrationController::class, 'verifyCode'])
                ->middleware('throttle:organization-verify');
            Route::post('/create-password', [OrganizationRegistrationController::class, 'createPassword'])
                ->middleware('throttle:organization-verify');
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

    // Tier 0 — any authenticated role, including customer: the permission
    // matrix has no secrets, and every role needs to be able to read its
    // own row.
    Route::middleware('auth:api')->group(function () {
        Route::get('/permissions', [PermissionController::class, 'index']);
    });

    // Tier 1 — org-scoped day-to-day operations: owner, admin, staff.
    // superadmin is deliberately NOT in this group — that's the mechanism
    // that makes it genuinely org-less (see User::primaryLocationId()).
    // org.active rejects every request here once the actor's active
    // organization has been blocked (see EnsureOrganizationNotBlocked).
    Route::middleware(['auth:api', 'role:owner,admin,staff', 'org.active'])->group(function () {
        // Products (unified - campsites + inventory) — read + POS sell
        Route::get('/products', [ProductController::class, 'index']);
        // Must precede /products/{product} — otherwise implicit route-model
        // binding treats "lookup-by-sku" as a {product} id and 404s before
        // this route is ever matched.
        Route::get('/products/lookup-by-sku', [ProductController::class, 'lookupBySku']);
        Route::get('/products/{product}', [ProductController::class, 'show']);
        Route::get('/products/{product}/ghl-stock', [ProductController::class, 'ghlStock']);

        // Customers — normal CRUD (delete/archive/restore/bulk-sync are Tier 2)
        Route::get('/customers', [CustomerController::class, 'index']);
        Route::post('/customers', [CustomerController::class, 'store']);
        // Must be registered before GET /customers/{customer} below —
        // otherwise "archived" would be swallowed by the {customer}
        // wildcard binding (same routing-order gotcha as
        // /products/lookup-by-sku above; this is owner/admin-only, but
        // route *registration* order is independent of which middleware
        // group a route sits in, so it has to come first regardless).
        Route::middleware('role:owner,admin')->group(function () {
            Route::get('/customers/archived', [CustomerController::class, 'archived'])
                ->middleware('permission:customer.archive.view');
            Route::post('/customers/archived/{archive}/restore', [CustomerController::class, 'restoreArchived'])
                ->middleware('permission:customer.archive.restore');
        });
        Route::get('/customers/{customer}', [CustomerController::class, 'show']);
        Route::put('/customers/{customer}', [CustomerController::class, 'update']);
        Route::post('/customers/{customer}/sync-ghl', [CustomerController::class, 'syncToGhl']);

        // Services storefront (bookable SERVICE products, GHL Rentals style) — read only
        Route::get('/services', [ServiceController::class, 'index']);
        Route::get('/services/{product}', [ServiceController::class, 'show']);

        // Bookings — staff run the booking system in full
        Route::get('/bookings', [BookingController::class, 'index']);
        Route::post('/bookings/quote', [BookingController::class, 'quote']);
        Route::post('/bookings', [BookingController::class, 'store']);
        Route::get('/bookings/{booking}', [BookingController::class, 'show']);
        Route::get('/bookings/{booking}/invoice', [BookingController::class, 'invoice']);
        Route::patch('/bookings/{booking}/status', [BookingController::class, 'updateStatus']);
        Route::patch('/bookings/{booking}/check-in-out', [BookingController::class, 'updateCheckInOut']);
        Route::post('/bookings/{booking}/confirm', [BookingController::class, 'confirm']);
        Route::post('/bookings/{booking}/pay-cash', [BookingController::class, 'payCash']);

        // Transactions — sole source of truth split across two tables as of
        // the 2026-08-10 refactor (no more generic /transactions*).
        Route::get('/rental-transactions', [RentalTransactionController::class, 'index']);
        Route::get('/product-transactions', [ProductTransactionController::class, 'index']);
        Route::post('/product-transactions', [ProductTransactionController::class, 'store']);
        Route::get('/product-transactions/{productTransaction}', [ProductTransactionController::class, 'show']);
        Route::patch('/product-transactions/{productTransaction}/payment-status', [ProductTransactionController::class, 'updatePaymentStatus']);
        Route::get('/product-transactions/{productTransaction}/invoice', [ProductTransactionController::class, 'invoice']);

        // Categories / Service Categories / Amenities / Features — read only
        // (staff need these for POS filter chips and the booking picker)
        Route::get('/categories', [CategoryController::class, 'index']);
        Route::get('/service-categories', [ServiceCategoryController::class, 'index']);
        Route::get('/amenities', [AmenityController::class, 'index']);
        Route::get('/features', [FeatureController::class, 'index']);

        // Site maps — viewing only; editing (the map builder) is Tier 2
        Route::get('/site-maps', [SiteMapController::class, 'index']);
        Route::get('/site-maps/{siteMap}', [SiteMapController::class, 'show']);
        Route::get('/site-map-icon-types', [SiteMapIconTypeController::class, 'index']);

        // Engage OAuth — Refresh Token page, staff-visible too (2026-08-14,
        // widened from owner/admin). The Client ID/Secret it authorizes
        // against are super-admin-only global data (see Tier 3 below); this
        // is only the per-org "connect/reconnect this org's GHL location"
        // action + the redirect URL info staff need to register it in GHL.
        Route::get('/settings/engage/oauth-info', [SettingsController::class, 'getOauthInfo']);
        Route::get('/settings/engage/authorize', [SettingsController::class, 'getAuthorizeUrl']);
        Route::post('/settings/engage/refresh-token', [SettingsController::class, 'refreshToken'])
            ->middleware('permission:engage.refresh_token');

        // Tier 2 — owner/admin only, nested inside Tier 1: management,
        // deletion, and GHL-sync-triggering actions.
        Route::middleware('role:owner,admin')->group(function () {
            // Self-service — the Profile page's "Business Information"
            // section, editing the caller's OWN organization (not the
            // superadmin cross-org drill-down under /superadmin/*).
            Route::get('/organization/profile', [OrganizationProfileController::class, 'show'])
                ->middleware('permission:organization.profile.view');
            Route::put('/organization/profile', [OrganizationProfileController::class, 'update'])
                ->middleware('permission:organization.profile.update');

            Route::post('/products', [ProductController::class, 'store']);
            Route::put('/products/{product}', [ProductController::class, 'update']);
            Route::delete('/products/{product}', [ProductController::class, 'destroy']);
            Route::post('/products/{product}/image', [ProductController::class, 'uploadImage']);
            Route::post('/products/{product}/categories', [ProductController::class, 'attachCategories']);
            Route::post('/products/{product}/sync-ghl', [ProductController::class, 'syncToGhl']);
            Route::post('/products/{product}/pull-ghl', [ProductController::class, 'pullFromGhl']);
            Route::post('/products/bulk-sync-ghl', [ProductController::class, 'bulkSync']);
            Route::post('/products/bulk-pull-ghl', [ProductController::class, 'bulkPull']);
            Route::post('/products/generate-skus', [ProductController::class, 'generateSkus']);

            // (archived/restore routes registered earlier, above the
            // /customers/{customer} wildcard — see the comment there.)
            Route::get('/customers/{customer}/deletion-preview', [CustomerController::class, 'deletionPreview']);
            Route::post('/customers/bulk-sync-ghl', [CustomerController::class, 'bulkSync']);
            Route::post('/customers/bulk-pull-ghl', [CustomerController::class, 'bulkPull']);
            Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])
                ->middleware('permission:customer.delete');

            Route::post('/services/pull-ghl', [ServiceController::class, 'pullFromGhl']);

            Route::get('/reports/summary', [ReportController::class, 'summary']);

            // Owner-only dashboard entity-count summary — narrower than the
            // owner+admin role: check this Tier 2 group already enforces, so
            // gated with its own permission action rather than relying on
            // the group's role:owner,admin alone.
            Route::get('/dashboard/organization-stats', [DashboardController::class, 'organizationStats'])
                ->middleware('permission:dashboard.organization_stats.view');

            Route::post('/categories', [CategoryController::class, 'store']);
            Route::get('/categories/{category}', [CategoryController::class, 'show']);
            Route::put('/categories/{category}', [CategoryController::class, 'update']);
            Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);
            Route::post('/categories/{category}/sync-ghl', [CategoryController::class, 'syncToGhl']);
            Route::post('/categories/bulk-sync-ghl', [CategoryController::class, 'bulkSync']);
            Route::post('/categories/pull-ghl', [CategoryController::class, 'pullFromGhl']);

            Route::post('/service-categories', [ServiceCategoryController::class, 'store']);
            Route::get('/service-categories/{serviceCategory}', [ServiceCategoryController::class, 'show']);
            Route::put('/service-categories/{serviceCategory}', [ServiceCategoryController::class, 'update']);
            Route::delete('/service-categories/{serviceCategory}', [ServiceCategoryController::class, 'destroy']);
            Route::post('/service-categories/pull-ghl', [ServiceCategoryController::class, 'pullFromGhl']);
            Route::post('/service-categories/{serviceCategory}/sync-ghl', [ServiceCategoryController::class, 'syncToGhl']);

            Route::post('/amenities', [AmenityController::class, 'store']);
            Route::put('/amenities/{amenity}', [AmenityController::class, 'update']);
            Route::post('/amenities/{amenity}/icon', [AmenityController::class, 'uploadIcon']);
            Route::delete('/amenities/{amenity}', [AmenityController::class, 'destroy']);

            Route::post('/features', [FeatureController::class, 'store']);
            Route::put('/features/{feature}', [FeatureController::class, 'update']);
            Route::post('/features/{feature}/icon', [FeatureController::class, 'uploadIcon']);
            Route::delete('/features/{feature}', [FeatureController::class, 'destroy']);

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

            // Engage Identifiers (Client ID/Secret/API Version/Base
            // URL/Redirect URI/Timezone/Scopes) are super-admin-only global
            // platform data (2026-08-14, see Tier 3 below,
            // Superadmin\EngageSettingsController) — owner/admin never get
            // these at all. getAuthorizeUrl/refreshToken/getOauthInfo moved
            // up into Tier 1 above (staff-visible too); manual tokens and
            // data sync stay owner/admin-only here.
            Route::get('/settings/engage/tokens', [SettingsController::class, 'getTokens']);
            Route::post('/settings/engage/tokens', [SettingsController::class, 'saveTokens']);
            Route::post('/settings/engage/pull-ghl', [SettingsController::class, 'pullAllGhlData'])
                ->middleware('permission:engage.data_sync');
            Route::get('/settings/engage/sync-log', [SettingsController::class, 'getLatestSyncLog']);
            Route::get('/settings/custom-fields', [SettingsController::class, 'getCustomFields']);
            Route::post('/settings/custom-fields', [SettingsController::class, 'storeCustomField']);
        });
    });

    // Tier 3 — super-admin platform routes. Deliberately no org.active:
    // super-admin is org-less by design and reads across every
    // organization via an explicit {organization} route id, never the JWT
    // active-org claim.
    Route::prefix('superadmin')->middleware(['auth:api', 'role:superadmin'])->group(function () {
        Route::get('/organizations', [OrganizationController::class, 'index']);
        Route::get('/organizations/{organization}', [OrganizationController::class, 'show']);
        Route::post('/organizations/{organization}/block', [OrganizationController::class, 'block'])
            ->middleware('permission:organization.block');
        Route::post('/organizations/{organization}/unblock', [OrganizationController::class, 'unblock'])
            ->middleware('permission:organization.unblock');
        Route::get('/organizations/{organization}/rentals', [OrganizationDataController::class, 'rentals']);
        Route::get('/organizations/{organization}/products', [OrganizationDataController::class, 'products']);
        Route::get('/organizations/{organization}/bookings', [OrganizationDataController::class, 'bookings']);
        Route::get('/organizations/{organization}/product-transactions', [OrganizationDataController::class, 'productTransactions']);
        Route::get('/organizations/{organization}/staff', [OrganizationDataController::class, 'staff']);

        // Engage Identifiers — genuinely global (2026-08-14), the
        // platform's own registered GHL marketplace app credentials, not
        // per-organization data — a standalone form, not a per-org drill-down
        // tab. Each organization's own connection status (has_token/
        // token_expires_at) is already shown on its own detail page above
        // via EngageOrganizationLocationResource, so no separate cross-org
        // list is needed here.
        Route::get('/engage-settings', [EngageSettingsController::class, 'show'])
            ->middleware('permission:engage.identifiers.view');
        Route::put('/engage-settings', [EngageSettingsController::class, 'update'])
            ->middleware('permission:engage.identifiers.update');

        // CMS pages (2026-08-14) — Terms of Service, Privacy Policy,
        // Support, About Us, Contact Us, Header, Footer. Same "genuinely
        // global" reasoning as Engage Identifiers above: one set of pages
        // for the whole platform, not per-organization.
        Route::get('/pages', [CmsPageController::class, 'index'])
            ->middleware('permission:cms.pages.view');
        Route::get('/pages/{slug}', [CmsPageController::class, 'show'])
            ->middleware('permission:cms.pages.view');
        Route::put('/pages/{slug}', [CmsPageController::class, 'update'])
            ->middleware('permission:cms.pages.update');
        Route::post('/pages/{slug}/image', [CmsPageController::class, 'uploadImage'])
            ->middleware('permission:cms.pages.update');
        Route::delete('/pages/{slug}/image', [CmsPageController::class, 'deleteImage'])
            ->middleware('permission:cms.pages.update');

        // Platform-level reference data, moved here from the owner/admin
        // group — same controller/path, gate changed to superadmin only.
        Route::get('/settings/countries', [SettingsController::class, 'getCountries']);
    });

    // Staff management — org-scoped only (owner/admin manage their own
    // org's staff). Super-admin no longer gets a flat cross-org /staff
    // list here (2026-08-14) — it sees staff per-organization instead, via
    // GET /superadmin/organizations/{organization}/staff on the org's own
    // drill-down page. Kept as its own top-level group (not nested inside
    // Tier 1/2) purely to match the existing route layout.
    Route::middleware(['auth:api', 'role:owner,admin', 'org.active'])->group(function () {
        Route::get('/staff', [StaffController::class, 'index']);
        Route::post('/staff', [StaffController::class, 'store']);
        Route::put('/staff/{staff}', [StaffController::class, 'update']);
        Route::delete('/staff/{staff}', [StaffController::class, 'destroy']);
    });
});

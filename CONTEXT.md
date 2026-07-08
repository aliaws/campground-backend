# Campground POS — Full System Context

> This file is the authoritative reference for how this system actually works.
> Large parts of it were rewritten after a deep audit (querying GHL's live API
> directly, cross-checking against the DB) that found several previous
> assumptions were wrong — those corrections are called out explicitly below.
> If something here conflicts with older comments in code, **trust this file
> first**, then re-verify against the code before assuming either is stale.

## Overview

A campground vacation booking POS with GoHighLevel (GHL) CRM integration. Everything sellable/bookable lives in one `products` table, split conceptually into:
- **Physical/Digital Products** (`product_type: PHYSICAL` / `DIGITAL`) — sellable merchandise, no booking.
- **Rental Services** (`product_type: SERVICE`, one-to-one `Rental` row) — bookable campsites, cabins, glamping, RV sites.

**Critical, hard-won fact — read this before touching Products/Services logic:**
`product_type = 'SERVICE'` is **necessary but not sufficient** to mean "this is a rental." The only GHL-verified signal for "this is genuinely a rental, not some other kind of service" is **`rentals.industry_type === 'rental'`**. Do not filter/split on `product_type` alone. See "Rentals vs Products" section below for the full reasoning and evidence — this was the source of a real, repeated bug this session.

---

## Repositories

| Project | Path |
|---------|------|
| Backend API | `~/Projects/Laravel/CampCommander/campground-backend` |
| Frontend | `~/Projects/Laravel/CampCommander/campground-frontend` |

---

## Backend Architecture (Laravel 13, PHP 8.4)

### Directory Structure
```
app/
├── Http/
│   ├── Controllers/Api/V1/          → Thin controllers (+ Public/ for guest-facing endpoints)
│   ├── Requests/                     → FormRequest validation
│   └── Resources/                    → API Resources (JSON shape)
├── Integrations/GHL/
│   └── GhlClient.php                 → Low-level HTTP client for GHL API (get/post/put/delete/poolGet)
├── Models/                           → Eloquent models, ULID primary keys
└── Services/                         → Business logic layer
    ├── ProductService.php            → Product CRUD + list filters (is_rental, base_listings_only)
    ├── ReservationService.php        → Booking creation, quote, availability
    ├── BookingPriceCalculator.php    → Pricing-rule engine (per-night price + discounts)
    ├── TransactionService.php
    ├── GhlService.php                 → Contact sync
    ├── GhlAuthService.php             → OAuth2 token refresh
    ├── GhlBookingService.php          → Creates GHL calendar bookings + invoices for a Reservation
    ├── GhlProductSyncService.php      → Physical/Digital product + price + category sync (Payments API)
    ├── GhlServiceSyncService.php      → Rental service pull (Calendar Services API, industryType=rental)
    └── GhlImageSyncService.php
```

### Key Models — the Product/Rental/RentalPricingRule split

**`Product`** (`products` table) — the common table for every sellable/bookable item:
- `product_type`: `PHYSICAL` | `DIGITAL` | `SERVICE`
- `Product::RENTAL_PROXIED_ATTRIBUTES` (a fixed list including `parent_product_id`, `variant_name`, `industry_type`, `ghl_service_id`, `available_quantity`, `max_quantity`, `booking_*`, `site_type`, `capacity`, `hookups`, `map_position`, `pet_friendly`, `ada_accessible`, `pricing_rule`) — reading/writing any of these on a `Product` instance is **transparently proxied to its `Rental` row** via magic `getAttribute`/custom setters in `Product.php`. So `$product->available_quantity` really means `$product->rental->available_quantity`. Only `SERVICE`-type products have a `Rental` row.
- Relations: `rental()` (hasOne), `parent()` (hasOneThrough via `rentals.parent_product_id`), `serviceVariants()` (hasManyThrough — other rentals whose `parent_product_id` points at this product), `categories()`, `prices()`, `variants()` (generic `ProductVariant`/option model — pricing options like Size/Color, **not the same thing as rental variants**), `amenities()`, `features()`.
- `isServiceVariant()`: `parent_product_id !== null`.

**`Rental`** (`rentals` table) — one-to-one with `Product` via `product_id`. Rental-specific data:
- `parent_product_id` — set only on variant rows; the base listing's row has this `null`. Each variant (e.g. "Regular", "Premium") is its **own separate `Product` + `Rental` row** — not a sub-record. This is required because each variant needs its own `engage_product_id` (see "why productType is unreliable" below) to invoice that specific variant's booking.
- `industry_type` — **the field that matters most**. Mirrors GHL's own `industryType` on the calendar service. Only `'rental'` means "real rental." Set by `GhlServiceSyncService::upsertFromDetail()` from GHL, or forced to `'rental'` by `ProductService::syncRentalData()` when a SERVICE product is created/edited through our own admin form (since anything created via "Add Service" is a rental by definition).
- `ghl_service_id` — GHL calendar service ID (scheduling layer). `null` for locally-created rentals never synced to GHL.
- `available_quantity` / `max_quantity` — nullable int; **`null` means unlimited**, checked everywhere (`ReservationService::remainingStock()`). A real number is compared against overlapping-date bookings.
- `booking_start_time` / `booking_end_time` — check-in-after / check-out-before times of day. **Informational policy only** — a `Reservation` has no time component, these are never enforced, just displayed.
- `booking_unit`, `min_duration`, `max_duration`, `duration_unit` — stay-length bounds, enforced in `ReservationService::assertDurationAllowed()`.
- `site_type`, `capacity`, `hookups`, `map_position`, `map_polygon`, `pet_friendly`, `ada_accessible`, `campsite_status` — campsite-specific display fields.
- `pricingRules()` / `activePricingRule()` — see pricing section below.

**`RentalPricingRule`** (`rental_pricing_rules` table) — **the actual source of truth for booking price**, via `Rental::activePricingRule()` (lowest `priority` wins; no admin UI creates >1 rule per rental yet) → `Rental::getPricingRuleAttribute()` reassembles the flat `pricing_rule` JSON shape:
```json
{
  "name": "...", "applies_to": "rental",
  "base_price": 35.00, "base_price_strategy": "per_day",
  "rules": [ { "type": "date_range"|"day_of_week"|"duration_discount"|"quantity_discount", "match": {...}, "value": 20, "valueType": "flat"|"percentage" } ],
  "security_deposit_amount": 0, "security_deposit_refundable": true,
  "payment_terms": { "type": "full" }, "ghl_pricing_rule_id": "..."
}
```

**Important gotcha — two "price" systems that look related but aren't**: `ProductPrice` (`product_prices` table, the admin form's generic "Pricing" tab, `productsApi.addPrice/updatePrice`) is a **separate, independent** field from `RentalPricingRule`. `BookingPriceCalculator::quote()` reads `$product->pricing_rule` (→ `RentalPricingRule`) **first**, and only falls back to `ProductPrice.amount` when no pricing rule exists at all. Editing the generic "Pricing" tab for an **already GHL-synced** rental has **zero effect** on what a guest is actually charged — it only affects the GHL payments-catalog display price. The admin form (`ProductsManager.tsx`) now has a distinct "Rental Base Price" control in the Campsite Details tab, bound to `pricing_rule.base_price`, which is what actually needs editing to change a booking's price.

**Same pattern, "categories"**: our own `Category` model (`categories` table, `product_categories` pivot, `engage_collection_id` synced to GHL "collections" via `GhlProductSyncService`) is a **completely separate, disconnected** system from `Rental.ghl_service_category_id` (GHL's own calendar-side "service category," pulled passively, never linked to our `Category` table, never synced anywhere). As of this writing, **0 of 38 rentals have a local Category assigned** — the admin Category picker has simply never been used for rentals. Known, deliberately not reconciled (would need more GHL API work); flagged as a real gap, not fixed.

### Rentals vs Products — the `industry_type` distinction (read before changing the split)

**Why `product_type` alone is unreliable**: GHL auto-creates a payments-layer "product" for every rental service purely so the booking can be invoiced (a `productId` to attach the invoice to) — it is not a curated catalog entry. Verified live: the exact same rental's own variants get **inconsistent `productType`** in GHL's own `products/` catalog (e.g. one variant tagged `SERVICE`, its sibling variant of the same listing tagged `DIGITAL`). This field cannot be trusted to mean "rental," and the invoicing-only product itself should never be surfaced as a standalone Product.

**The correct signal**: `GET calendars/services?locationId=...&industryType=rental` (GHL, server-side filtered) is the authoritative rental registry. Cross-checking this against the DB found a stale row: a product ("300 - Forest Edge Tent") had a real `ghl_service_id` and locally-stored `industry_type = 'rental'`, but querying GHL directly (even with `industryType=rental` in the query — that param does **not** coerce the answer, it's just required by the endpoint) showed its true `industryType` is `"service"`. That row was corrected to `industry_type = 'service'` and now correctly shows in Products, not Services.

**Current filter implementation** (`ProductService::list()`):
```php
// is_rental=1 (Services page): product_type=SERVICE AND rentals.industry_type='rental'
// is_rental=0 (Products page): everything else, INCLUDING SERVICE-type products
//   whose industry_type isn't 'rental' (e.g. the corrected Forest Edge Tent)
// base_listings_only=1: additionally excludes rows where rentals.parent_product_id is set
//   (i.e. hides variants from the top-level list — they're shown nested instead)
```
Frontend: `ProductsManager.tsx` (shared component, `mode: 'goods' | 'services'`) → `app/admin/products/page.tsx` (`mode="goods"`) and `app/admin/services/page.tsx` (`mode="services"`), linked from `Sidebar.tsx`.

**Variant display**: a rental's variants are never shown as separate top-level rows in Services — only base listings (`parent_product_id IS NULL`) show at the top level. Expanding a row (or opening its edit modal's "Variants" tab, which for services mode shows the *real* rental variants, not the irrelevant generic `ProductVariant` editor) shows the **base itself first** (labeled "(default)", using its own `Rental.variant_name`, e.g. "Regular") followed by the other `service_variants` as peers — the base's own variant identity is never left implicit.

**Preventing future corruption**: `GhlProductSyncService::bulkPullFromGhl()` ("Pull All from GHL" on the Products page) fetches GHL's entire `products/` catalog. Without protection, this would stub in the invoicing-only payment products behind rentals as fake standalone Products (using GHL's unreliable `productType`). Fixed via `GhlServiceSyncService::fetchRentalProductIds()` (one `calendars/services?industryType=rental` call, returns every rental-linked payment `productId`) — `bulkPullFromGhl()` fetches this set once and skips any GHL catalog entry on it. Verified: running the bulk pull produces `created: 0` / zero duplicate `engage_product_id` rows.

### Availability & real-time booking

`Rental.available_quantity`: `null` = unlimited (checked everywhere). **Known historical bug, fixed**: `ProductsManager.tsx` had the state/payload plumbing for `available_quantity` but no actual `<Input>` bound to it, so every locally-created rental (29 of 38 at the time) silently got `NULL`. Fixed by adding the input (Campsite Details tab) and backfilling existing NULLs to `1`.

`ReservationService`:
- `remainingStock(Product, checkIn, checkOut): ?int` — shared helper, `null` = unlimited, else stock minus overlapping non-cancelled reservation quantities. Used by both:
  - `assertAvailable()` (private, throws `InvalidArgumentException` if quantity requested exceeds remaining) — called explicitly in `create()`, actually rejects overbooking at creation time.
  - `quote()` — **never throws on insufficient stock**, just adds `remaining_quantity`/`is_available: bool` to its returned array, so the UI can show live status ("2 of 3 available" / "Fully booked") instead of a hard error while previewing.
- `assertDurationAllowed()` — still throws in `quote()` for min/max stay violations (unrelated to availability, always a hard validation).

Frontend: `usePublicServiceQuote`/`useServiceQuote` responses (typed via `BookingQuote` in `types/booking.ts`) now include `remaining_quantity`/`is_available`. Both the customer rental page (`app/(customer)/rentals/[id]/page.tsx`) and the POS booking modal (`app/pos/services/[id]/page.tsx`) show an inline "✓ N of M available" / "✕ Fully booked for these dates" message and cap the quantity stepper by the live `remaining_quantity` (falling back to the product's static `maxQuantity` before a quote exists), and disable the booking button when `is_available === false`. Check-in/check-out times (`booking_start_time`/`booking_end_time`) are displayed (not enforced) on both booking pages and the guest confirmation page (`app/(customer)/rentals/confirmation/[reservationId]/page.tsx`), via `GuestReservationResource`.

**Deliberately not built** (discussed and declined for now): pooled stock across variants (each variant keeps its own independent `available_quantity` — a "Regular" booking never affects "Premium"'s count) and a full blocked-dates calendar picker (current UX is a live message after picking plain dates, not a calendar with greyed-out days).

### Reservation lifecycle (`requested` → `confirmed`, or `pending` → `confirmed`)

Two distinct creation paths through `ReservationService::create(array $data, bool $autoConfirm = true)`:
- **Staff-created** (internal POS, `autoConfirm: true`, default): immediately synced to GHL — `status: 'pending'` then a real `GhlBookingService::createBooking()` call (contact sync, calendar booking, invoice, payment email) happens inline. Unchanged, original behavior.
- **Guest-submitted** (public booking form, `autoConfirm: false`): created as `status: 'requested'`, purely local — **no GHL contact sync, no booking, no invoice, no transaction** happen at submission time.

`ReservationService::confirm(Reservation)` — the **only** path that turns a `requested` reservation real: calls `GhlBookingService::createBooking()` (contact sync + booking + invoice + payment email, NOT wrapped in try/catch — failures must surface to staff, unlike the staff-created path which logs and swallows GHL errors), sets `status: 'confirmed'`, creates the `Transaction`. Triggered by the "Confirm" button in `app/pos/reservations/page.tsx`, hitting `POST /reservations/{id}/confirm`.

Guard: `ReservationService::updateStatus()` (the generic `PATCH /reservations/{id}/status` endpoint) explicitly rejects `requested → confirmed` transitions — must go through `confirm()` instead, since only that path actually creates the GHL booking.

**UX note on `confirm()` latency**: the full GHL round trip (contact sync → fees/taxes → booking create → invoice fetch → payment email) can take **10-20+ seconds** for real GHL calls. The POS Confirm button shows an explicit "Confirming… this may take up to 15-20 seconds" banner and success/error banners on completion — earlier versions had no such feedback and looked broken (it wasn't; it was just slow with zero UI feedback).

### GHL Integration — key sync entry points

| Action | Method | Notes |
|---|---|---|
| Pull rentals from GHL | `GhlServiceSyncService::pullServices()` | Server-filtered `industryType=rental`; only path that legitimately sets `industry_type='rental'` from real GHL data |
| Pull products from GHL | `GhlProductSyncService::bulkPullFromGhl()` | Now excludes rental-linked payment product ids (see above) |
| Sync a product to GHL | `GhlProductSyncService::syncProductToGhl()` | Pushes `collectionIds` from local `Category.engage_collection_id` |
| Create/sync a customer contact | `GhlService::syncContactToGhl()` | Called lazily inside `GhlBookingService::createBooking()`, only if `ghl_contact_id` is null |
| Create a real booking + invoice | `GhlBookingService::createBooking()` | The one method that does contact sync + calendar booking + invoice + payment email; called by both the staff auto-confirm path and `ReservationService::confirm()` |
| Update/cancel an existing GHL booking | `GhlBookingService::updateBookingStatus()` | No-op if `ghl_booking_id` is null — never creates a booking, only touches an existing one |

---

## Frontend Architecture (Next.js 16, React 19)

### Route groups
- `app/(customer)/...` — the guest-facing booking site (site root, own layout with header/footer nav — Gallery/About/Contact/Login). Deliberately does not use `AppLayout`.
- `app/pos/...` — internal staff POS (dashboard, reservations, services, transactions, campsite-map, staff), uses `AppLayout` + `Navbar` (blue theme).
- `app/admin/...` — admin panel (Products, Services, Customers, Configurations, Engage Settings), uses `AppLayout` + `Sidebar`.
- Auth guard (`AppLayout`) is client-side only — no `middleware.ts` exists. Reads `AuthContext` (token in `localStorage('auth_token')`), renders `null` while loading/unauthenticated, redirects via `useEffect`. No content flash, but also no server-side gate.

### Key shared components
- `components/products/ProductsManager.tsx` — shared Products/Services admin CRUD (see split logic above). `mode: 'goods' | 'services'` controls filters, labels, and which "Variants" tab content renders.
- `components/ui/AppLayout.tsx`, `Navbar.tsx`, `Sidebar.tsx` — POS/admin chrome.
- `app/(customer)/layout.tsx` — customer site chrome (sticky header, green branding, active-pill nav, mobile hamburger).

### Booking flow (guest)
```
/ (customer home, listings)
  → /rentals/{id}                         Pick variant, dates, quantity
      live quote via usePublicServiceQuote (now includes remaining_quantity/is_available)
      → /rentals/checkout                 Guest enters name/email/phone
          → POST /public/reservations     autoConfirm=false → status: 'requested'
          → /rentals/confirmation/{id}    "We'll review and contact you" — NOT a payment link
```
Staff later confirms in `/pos/reservations` → `POST /reservations/{id}/confirm` → real GHL booking/invoice/payment email sent then.

### Types
- `types/booking.ts` — `BookingQuote` (now includes `remaining_quantity`, `is_available`), `QuoteParams`.
- `types/product.ts` — `Product` (includes `parent_product_id`, `variant_name`, `service_variants?: Product[]`, `pricing_rule: PricingRule | null`).
- `types/guestReservation.ts` — `GuestReservation` (now includes `booking_start_time`/`booking_end_time`).
- `types/reservation.ts` — `Reservation.status`: `'requested' | 'pending' | 'confirmed' | 'cancelled'`.

---

## Known gaps / deliberately deferred (do not assume these are "todo bugs" to silently fix — confirm with the user first)

- Dead "New Reservation" button on `app/pos/reservations/page.tsx` — no `onClick` handler at all. Found during exploration, unrelated to the availability work, not yet fixed.
- Category duality (local `Category` system vs GHL's calendar-side `serviceCategoryId`) — not reconciled, 0 rentals have a local category.
- Pooled variant stock and a full blocked-dates calendar — both explicitly discussed and declined in favor of the simpler independent-stock / live-message approach.
- 4 orphaned `Rental` rows exist in the DB with no matching `Product` (dangling FK from past deletions) — harmless (invisible to any Product-based query) but not cleaned up.

---

## Docker Environment

```bash
docker run -d --name postgres \
  -e POSTGRES_USER=user -e POSTGRES_PASSWORD=admin -e POSTGRES_DB=demo \
  -p 5432:5432 postgres:16
```
`.env`: `DB_CONNECTION=pgsql`, `DB_HOST=localhost`, `DB_PORT=5432`, `DB_DATABASE=campground_pos`, `DB_USERNAME=user`, `DB_PASSWORD=admin`.

## Key Commands

```bash
# Backend
php artisan serve                    # API on :8000
php artisan migrate
php artisan db:seed

# Frontend (Node 20 required for Playwright — this box's default `node` is v16;
# use `export PATH="/home/ali/.nvm/versions/node/v20.20.2/bin:$PATH"` first)
cd ~/Projects/Laravel/CampCommander/campground-frontend
npm run dev
```

Deployment: a separate server (`ssh` box, `/var/www/html/campground-backend`, nginx + php8.4-fpm) runs the `ali/add_features_products` branch. **Gotcha hit once**: `php artisan route:cache` had been run at some point and gone stale after later `git pull`s — new routes 404'd with "The route ... could not be found" until `php artisan route:clear` was run. If a route works locally but 404s on the deployed server, check `bootstrap/cache/routes-v7.php`'s mtime vs the latest commit before assuming a code problem.

## Login credentials (from seed — unverified this session; only ever used via temporary bcrypt overrides for browser testing, always restored to the original hash afterward)
| Name | Email | Password | Role |
|------|-------|----------|------|
| Test Admin | `admin@campground.com` | `password` (per original seeder — not independently re-verified) | admin |

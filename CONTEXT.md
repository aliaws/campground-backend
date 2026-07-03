# Campground POS — Full System Context

## Overview

A campground vacation booking POS with GoHighLevel (GHL) CRM integration. Two product concepts:
- **Physical/Digital Products** (`PHYSICAL` / `DIGITAL`) — sellable merchandise
- **Services/Rentals** (`SERVICE`) — bookable campsites, cabins, glamping, RV sites

**Key architectural decision:** GHL has two separate APIs:
- **Services API** (`services.leadconnectorhq.com/calendars/services`) — for rental bookings
- **Products API** (`products.leadconnectorhq.com`) — for physical/digital merch

When a GHL Service is created, GHL auto-creates a Product in Payments. Our backend stores everything in the unified `products` table, but the frontend treats them differently:
- `/services` page → only GHL rental services (with `ghl_service_id`), showing variants inline
- `/products` page → all products (PHYSICAL, DIGITAL, SERVICE)

---

## Repositories

| Project | Path |
|---------|------|
| Backend API | `~/Projects/Laravel/CampCommander/campground-backend` |
| Frontend | `~/Projects/Laravel/CampCommander/campground-frontend` |

---

## Backend Architecture (Laravel 13.x + PostgreSQL)

### Stack
- **PHP** 8.3, **Laravel** 13, **PostgreSQL** 16 (Docker), **Sanctum** (token auth)
- Docker: `postgres:16`, user=`user`, password=`admin`, port=5432, DB=`campground_pos`

### Directory Structure
```
app/
├── Http/
│   ├── Controllers/Api/V1/   → Thin controllers
│   ├── Requests/              → FormRequest validation
│   └── Resources/             → API Resources (JSON shape)
├── Integrations/GHL/
│   ├── GhlClient.php          → Low-level HTTP client for GHL API
│   └── GhlWebhookHandler.php  → Inbound webhook receiver
├── Models/                    → 17 Eloquent models (all ULID primary keys)
├── Providers/
└── Services/                  → Business logic layer
    ├── ProductService.php
    ├── ReservationService.php
    ├── TransactionService.php
    ├── BookingPriceCalculator.php   → Pricing rule engine
    ├── GhlService.php               → Contact/opportunity sync
    ├── GhlAuthService.php           → OAuth2 token refresh
    ├── GhlProductSyncService.php    → Product/price/variant/category sync
    ├── GhlServiceSyncService.php    → GHL Calendar Services/Rentals pull
    └── GhlImageSyncService.php
```

### Route Structure (`routes/api.php`)
All prefixed with `/api/v1`:

| Group | Endpoints |
|-------|-----------|
| Auth | `POST /auth/login`, `POST /auth/register`, `POST /auth/logout`, `GET /auth/me` |
| Products | CRUD + image upload, prices, categories attach |
| Services | `GET /services`, `GET /services/{id}`, `POST /services/pull-ghl` |
| Customers | CRUD + `POST /{id}/sync-ghl`, `POST /bulk-sync-ghl`, `POST /bulk-pull-ghl` |
| Reservations | CRUD + `POST /quote`, `PATCH /{id}/status` |
| Transactions | CRUD + `PATCH /{id}/payment-status`, `GET /{id}/invoice` |
| Categories | CRUD + GHL sync/pull |
| Amenities/Features | CRUD |
| Settings | Engage OAuth, countries, custom fields |
| Webhooks | `POST /webhooks/ghl` (no auth) |

### Key Models

**Product** — Unified `products` table:
- `product_type`: `PHYSICAL` | `DIGITAL` | `SERVICE`
- `parent_product_id`: links service variants to base product (GHL model)
- `variant_name`, `ghl_service_id`: GHL Calendar service variant fields
- Booking fields: `booking_unit`, `min_duration`, `max_duration`, `duration_unit`, `booking_start_time`, `booking_end_time`, `max_quantity`
- `pricing_rule` (JSON): GHL pricingRule shape with base_price, rules array
- `fromPrice()`: computed storefront "From $/day" across variants
- Relations: `categories()`, `prices()`, `variants()`, `serviceVariants()` (self-referencing), `amenities()`, `features()`, `reservations()`
- Scopes: `byTenant()`, `service()`, `physical()`, `digital()`

**Reservation**:
- `customer_id`, `product_id`, `check_in_date`, `check_out_date`
- `quantity`, `base_amount`, `discount_amount`, `total_amount`, `security_deposit_amount`
- `price_breakdown` (JSON): full nightly breakdown from quote engine
- `status`: `pending` | `confirmed` | `cancelled`
- `ghl_opportunity_id`: linked GHL opportunity

**Customer**:
- `name`, `email`, `phone`, `address` (JSON)
- `ghl_contact_id`, `ghl_sync_status`, `ghl_last_synced_at`

**GHL Sync Fields** (on Product, Category, Customer, Reservation):
- `engage_product_id` / `engage_collection_id` / `ghl_contact_id` / `ghl_opportunity_id` — GHL entity IDs
- `engage_sync_status`: `not_synced` | `pending` | `synced` | `error`
- `engage_last_synced_at`

### Services API — GHL-Shaped Response (`ServiceResource`)

The `/services` endpoint returns data shaped like the GHL Services API, NOT the Product model. Uses `app/Http/Resources/ServiceResource.php`.

**Listing** (`GET /services`):
```json
{
  "success": true,
  "data": [
    {
      "id": "01kwksv1z65tgkwn5zew325cmb",          // local DB ID
      "_id": "6966e6a92c860677c2fab136",             // GHL service ID
      "isActive": true,
      "name": "Pine Ridge Family Campsite",           // clean name (no variant suffix)
      "slug": "300---forest-edge-tent-copy",
      "description": "...",
      "coverImage": "https://...",
      "bookingUnit": "day",
      "quantity": 1,
      "maxQuantity": 1,
      "images": [{ "_id": "...", "url": "...", "name": "Image 1", "position": 0 }],
      "serviceCategoryId": "6952b37f7931bd505749c9ed",
      "categoryName": "Cabins",
      "variantName": "Regular",                       // base variant name
      "isVariantsEnabled": true,                      // true when >1 variant
      "isVariant": false,
      "variants": [                                   // ALL variants including base
        {
          "id": "01kwksv1z65tgkwn5zew325cmb",        // local DB ID
          "_id": "6966e6a92c860677c2fab136",          // GHL service ID
          "variantName": "Regular",
          "payment": { "amount": 35, "description": "..." },
          "isVariant": false,
          "variantId": null,                          // null for base
          "productId": "6966e6a94798be5a73a44f5d"     // GHL Payments productId
        },
        {
          "id": "01kwksv559jjtn5dgyzp4rf8s8",
          "_id": "6a4642b21a2c4582d7da99e0",
          "variantName": "Exclusive",
          "payment": { "amount": 23 },
          "isVariant": true,
          "variantId": "6966e6a92c860677c2fab136",    // points to base GHL service ID
          "productId": "6a4642b3c8e4bb0514c75f5d"
        }
      ],
      "pricingRule": {                                // full GHL pricingRule
        "name": "Pine Ridge Family Campsite Pricing",
        "appliesTo": "rental",
        "basePrice": { "value": 35, "strategy": "per_day" },
        "rules": [
          { "type": "date_range", "match": { "from": "2026-07-09", "to": "2026-07-22" }, "value": 23, "valueType": "flat" },
          { "type": "day_of_week", "match": { "dayOfWeek": 1 }, "value": 7.3, "valueType": "percentage" },
          { "type": "duration_discount", "match": { "duration": 23, "durationUnit": "day" }, "value": 20, "valueType": "percentage" }
        ],
        "securityDeposit": { "amount": 0, "refundable": true },
        "paymentTerms": { "type": "full" }
      },
      "bookingPeriodType": "date-selection",          // derived: fixed | date-selection | date-time-selection
      "minDuration": 1,
      "maxDuration": 30,
      "bookingStartTime": null,
      "bookingEndTime": null,
      "fromPrice": 23,                                // min across variants
      "categories": [...],
      "amenities": [...],
      "features": [...]
    }
  ],
  "message": "Services retrieved."
}
```

**Important:** Only services synced FROM GHL appear (those with `ghl_service_id IS NOT NULL`). Local service-type products are excluded from this endpoint.

### GHL Integration — Two-Way Sync

**Outbound (Local → GHL):**
| Trigger | GHL Action |
|---------|------------|
| Customer created/updated | Create/update GHL contact |
| Reservation created | Create GHL opportunity |
| Reservation status changed | Update GHL opportunity stage |
| Product created/updated | Create/update GHL product (with variants, prices, image) |
| Category created/updated | Create/update GHL collection |

**Inbound (GHL → Local):**

**Manual pull** (button in admin UI):
| Action | Endpoint |
|--------|----------|
| Pull all GHL contacts → local customers | `POST /customers/bulk-pull-ghl` (`GhlService::bulkPullContacts()`) |
| Pull all GHL services/rentals → local products | `POST /services/pull-ghl` (`GhlServiceSyncService::pullServices()`) |

**Webhook-driven (GHL → Local):**
| Event | Action |
|-------|--------|
| `contact.created` | Create/update customer with `ghl_contact_id` |
| `contact.updated` | Update matching customer |
| `opportunity.created` | Link to latest unlinked reservation |
| `opportunity.stage_changed` | Map: `new→pending`, `booked→confirmed`, `lost→cancelled` |

**GHL Service Sync** (`GhlServiceSyncService::pullServices()`):
- Pulls all Calendar Services with `industryType=rental` from `GET /calendars/services`
- For each service, fetches detail from `GET /calendars/services/{id}` (has pricingRule, variants, durations)
- Base services (`variantId=null`) become product rows with `parent_product_id=null`
- Variant services (`variantId=parentId`) get `parent_product_id` linking to base
- Name is saved as-is from GHL (e.g., "Pine Ridge Family Campsite") — NOT with variant suffix
- `pricingRule` → `pricing_rule` JSON on the product
- Only services with `ghl_service_id` appear on the frontend services page

### Booking Price Calculator (`BookingPriceCalculator`)

Computes per-night quote:
1. **Base price** per day from `pricing_rule.base_price`
2. **Nightly rules** (applied per-date):
   - `date_range`: flat/% override for specific dates (peak season)
   - `day_of_week`: % adjustment for specific days (weekend pricing)
3. **Subtotal discounts** (applied to total):
   - `duration_discount`: %/flat off for long stays
   - `quantity_discount`: %/flat off for multi-unit
4. Output: `nights`, `nightly[]` (per-date breakdown), `subtotal`, `discounts[]`, `total_amount`, `security_deposit_amount`, `grand_total`

---

## Frontend Architecture (Next.js 16 + React 19 + TypeScript)

### Stack
- **Next.js** 16, **React** 19, **TypeScript** 5
- **TanStack React Query** 5, **Axios**, **Tailwind CSS** 4
- **Lucide React** icons

### Directory Structure
```
app/                          → Next.js App Router pages
├── services/                 → Storefront: browse & book GHL rental services
│   ├── page.tsx              → Service listing with filters (category, price, dates, sort)
│   └── [id]/page.tsx         → Service detail + variant selection + booking panel + BookingModal
├── customers/page.tsx        → Public customers list
├── reservations/page.tsx     → Reservation list with status filter
├── products/                 → Product listing/detail (ALL types)
├── admin/                    → Admin CRUD interfaces
│   ├── customers/page.tsx    → Admin: full CRUD + GHL sync + Pull from GHL button
│   ├── products/             → Admin: product create/edit
│   ├── categories/           → Admin: category management
│   ├── amenities/            → Admin: amenenity management
│   ├── features/             → Admin: feature management
│   ├── prices/page.tsx       → Admin: all prices view
│   ├── engages/              → GHL OAuth settings
│   └── ...                   → countries, custom-fields, webhooks
├── dashboard/page.tsx        → POS dashboard
├── login/page.tsx            → Auth login
├── register/page.tsx         → Auth registration
├── transactions/page.tsx     → Transactions list
├── campsite-map/page.tsx     → Map view
├── reports/page.tsx          → Reports
├── settings/                 → Settings pages
├── staff/page.tsx            → Staff view
├── layout.tsx                → Root layout with providers
└── page.tsx                  → Home page
components/
├── ui/                       → Reusable UI components
│   ├── AppLayout.tsx         → Main app shell with sidebar
│   ├── Sidebar.tsx           → Navigation sidebar
│   ├── Navbar.tsx            → Top navbar
│   ├── Button.tsx, Input.tsx, Select.tsx, Textarea.tsx
│   ├── Card.tsx, Table.tsx, Modal.tsx, Badge.tsx
│   ├── CategorySelect.tsx
│   └── Providers.tsx         → QueryClient + Auth provider wrapper
└── dashboard/                → Dashboard widgets
    ├── CampsiteGrid.tsx
    ├── ActiveReservations.tsx
    ├── RevenueSummary.tsx
    └── TransactionBreakdown.tsx
hooks/                        → React Query hooks
├── useCustomers.ts           → Customer CRUD + sync + pull from GHL
├── useServices.ts            → Services list, detail, quote, pull from GHL
├── useReservations.ts        → Reservation CRUD + status update
├── useProducts.ts            → Product CRUD
├── useCategories.ts          → Category CRUD
├── useAmenities.ts           → Amenity CRUD
├── useFeatures.ts            → Feature CRUD
└── useTransactions.ts        → Transaction CRUD
lib/
├── api/                      → API client modules
│   ├── client.ts             → Axios instance with auth interceptor
│   ├── auth.ts               → Auth API
│   ├── customers.ts          → Customer API
│   ├── services.ts           → Services API (list, detail, quote, pull)
│   ├── products.ts           → Products API
│   ├── reservations.ts       → Reservations API
│   ├── transactions.ts       → Transactions API
│   ├── catalog.ts            → Categories/amenities/features API
│   └── settings.ts           → Settings API
├── auth/AuthContext.tsx       → Auth context provider
├── theme/ThemeContext.tsx     → Theme provider
└── utils/image.ts            → Image URL helper
types/                        → TypeScript interfaces
├── api.ts                    → ApiResponse<T>, PaginatedResponse<T>, User
├── service.ts                → Service, ServiceVariant, ServicePricingRule (GHL-shaped)
├── customer.ts               → Customer
├── product.ts                → Product, PricingRule, PricingRuleLine, ProductPrice, etc.
├── booking.ts                → BookingQuote, NightlyPrice, QuoteDiscount, QuoteParams
├── reservation.ts            → Reservation
├── transaction.ts            → Transaction
└── ghl.ts                    → GHL-related types
```

### Auth Flow
1. `lib/auth/AuthContext.tsx` — wraps app, checks localStorage for token
2. On login: `POST /api/v1/auth/login` → stores token in `localStorage('auth_token')`
3. `lib/api/client.ts` — Axios interceptor adds `Authorization: Bearer <token>` to every request
4. On 401: clears token, redirects to `/login`
5. Login users from seeder: `test@example.com` / `password`, `admin@campground.com` / `password`

### Booking Flow (End-to-End)
```
/services                          → Browse GHL rental services (grid/list, filter by category/price/dates)
  ↓                                Only shows services with ghl_service_id (actual GHL rentals)
/services/{id}                     → Service detail page
  ├── Shows variants[] from GHL API (e.g. Regular, Exclusive, Premium)
  ├── Pick start date + duration
  ├── Choose quantity
  ├── Live quote from POST /reservations/quote (auto-refetches)
  └── "Book Now" → BookingModal
        ↓
BookingModal                       → Confirm booking
  ├── Loads customers via useCustomers({ per_page: 100 }) — returns array directly
  │   useCustomers returns res.data.data (already extracted), so access as Array.isArray(data) ? data : data?.data || []
  ├── Select customer from dropdown
  └── Confirm → POST /api/v1/reservations
        ↓
Backend:
  1. ReservationService::create()
     → BookingPriceCalculator::quote() for pricing
     → Reservation::create() with price_breakdown
     → GhlService::createOpportunity() → GHL opportunity
  2. TransactionService::autoCreateFromReservation()
     → Creates Transaction + TransactionItem
        ↓
Redirect to /reservations           → View reservation list
```

### Services API Client (frontend)
```ts
// lib/api/services.ts — uses Service type (GHL-shaped), NOT Product
servicesApi.list(params?)              → GET /services → Service[]
servicesApi.get(id)                    → GET /services/{id} → Service
servicesApi.quote(data)                → POST /reservations/quote → BookingQuote
servicesApi.pullFromGhl()             → POST /services/pull-ghl
```

### Customer API Client (frontend)
```ts
// lib/api/customers.ts
customersApi.list(params?)              → GET /customers
customersApi.get(id)                    → GET /customers/{id}
customersApi.create(data)               → POST /customers
customersApi.update(id, data)           → PUT /customers/{id}
customersApi.delete(id)                 → DELETE /customers/{id}
customersApi.syncToGhl(id)              → POST /customers/{id}/sync-ghl
customersApi.bulkSyncToGhl()            → POST /customers/bulk-sync-ghl
customersApi.bulkPullFromGhl()          → POST /customers/bulk-pull-ghl
```

### API Response Format
All responses follow:
```json
{
  "success": true,
  "data": { ... },          // Single object or PaginatedResponse
  "message": "..."
}
```

Paginated:
```json
{
  "data": [ ... ],
  "current_page": 1,
  "last_page": 5,
  "per_page": 15,
  "total": 72,
  "next_page_url": "...",
  "prev_page_url": null
}
```

---

## GHL Services API Reference

Source files in `json/` folder:

- `all service.json` — `GET /calendars/services?locationId=...&industryType=rental` — flat list of all services (base + variants)
- `single service details.json` — `GET /calendars/services/{id}?locationId=...` — base service with embedded `variants[]`, `pricingRule`, durations
- `sindle service variant details.json` — `GET /calendars/services/{variantId}` — single variant with its own `pricingRule`

**Key GHL fields per service:**
| Field | Description |
|-------|-------------|
| `_id` | GHL service/rental record ID |
| `name` | Service name (clean, no variant suffix) |
| `variantName` | Variant label (Regular, Premium, Exclusive, etc.) |
| `isVariant` | `true` if this is a variant, `false` if base |
| `variantId` | GHL service ID of the parent (null for base) |
| `productId` | GHL Payments-layer Product ID (auto-created) |
| `pricingRule` | Full pricing config with `basePrice`, `rules[]`, `securityDeposit` |
| `bookingPeriodType` | `fixed` / `date-selection` / `date-time-selection` |
| `minDuration` / `maxDuration` | Booking duration bounds |
| `serviceCategoryId` | GHL service category ID (separate from Payments collections) |

---

## Docker Environment

```bash
# PostgreSQL (running)
docker run -d \
  --name postgres \
  -e POSTGRES_USER=user \
  -e POSTGRES_PASSWORD=admin \
  -e POSTGRES_DB=demo \
  -p 5432:5432 \
  postgres:16
```

**.env** key values:
```
DB_CONNECTION=pgsql
DB_HOST=localhost
DB_PORT=5432
DB_DATABASE=campground_pos
DB_USERNAME=user
DB_PASSWORD=admin
```

---

## Key Commands

```bash
# Backend
php artisan serve                    # Start API server on :8000
php artisan migrate                  # Run pending migrations
php artisan db:seed                  # Seed database

# Frontend (separate terminal)
cd ~/Projects/Laravel/CampCommander/campground-frontend
npm run dev                          # Start Next.js dev server
```

## Login Credentials (from seed)
| Name | Email | Password | Role |
|------|-------|----------|------|
| Test User | `test@example.com` | `password` | staff |
| Test Admin | `admin@campground.com` | `password` | admin |
| Staff User | `staff@campground.com` | `password` | staff |

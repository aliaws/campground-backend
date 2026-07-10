# Campground POS — Backend API

Laravel 13 REST API for a campground point-of-sale system with multi-campground (SaaS) support and GoHighLevel CRM integration.

## Requirements

- PHP ^8.3
- Composer
- SQLite (dev) / PostgreSQL 16+ (production)
- Node.js & NPM (for Vite/assets)

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve   # http://localhost:8000
```

Or use the all-in-one dev script (starts server, queue worker, log tail, and Vite):

```bash
composer dev
```

## Environment

Key `.env` values:

| Key | Default | Description |
|-----|---------|-------------|
| `DB_CONNECTION` | `sqlite` | Database driver (`sqlite` for dev, `pgsql` for production) |
| `DB_HOST` | `127.0.0.1` | Database host (PostgreSQL) |
| `DB_PORT` | `5432` | Database port (PostgreSQL) |
| `DB_DATABASE` | `campground_pos` | Database name (PostgreSQL) |
| `SANCTUM_STATEFUL_DOMAINS` | `localhost:3000` | Frontend SPA domain |

## Authentication

Uses **Laravel Sanctum** with token-based auth:

1. `POST /api/v1/auth/login` returns `{ user, token }`
2. Frontend stores the token in `localStorage`
3. Subsequent requests include `Authorization: Bearer <token>` header
4. 401 responses trigger token clear and redirect to `/login`

```bash
# Login
curl -X POST http://localhost:8000/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"email":"admin@campground.com","password":"password"}'

# Use token
curl http://localhost:8000/api/v1/auth/me \
  -H "Authorization: Bearer <token>"
```

## API Endpoints

All endpoints are prefixed with `/api/v1`.

### Auth

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| POST | `/auth/login` | No | Login, returns user + token |
| POST | `/auth/logout` | Sanctum | Revoke current token |
| GET | `/auth/me` | Sanctum | Current authenticated user |

### Webhooks

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| POST | `/webhooks/ghl` | No | GoHighLevel webhook receiver |

### Products (unified campsites + inventory)

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/products` | List products (filter: `?type=rental&sub_type=campsite&status=available&category_id=...&search=...`) |
| POST | `/products` | Create product |
| GET | `/products/{id}` | Get product with category, prices, variations, amenities, features |
| PUT | `/products/{id}` | Update product |
| DELETE | `/products/{id}` | Soft-delete product |
| POST | `/products/{id}/image` | Upload product image (max 2MB) |
| GET | `/products/{id}/prices` | Get seasonal/dynamic prices |
| POST | `/products/{id}/prices` | Add price tier (`label`, `price`, `valid_from`, `valid_until`) |
| GET | `/products/{id}/variations` | Get variations (e.g., RV site sizes) |
| POST | `/products/{id}/variations` | Add variation (`name`, `sku`, `price_modifier`) |

### Customers

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/customers` | List customers |
| POST | `/customers` | Create customer (auto-syncs to GHL) |
| GET | `/customers/{id}` | Get customer |
| PUT | `/customers/{id}` | Update customer (auto-syncs to GHL) |
| DELETE | `/customers/{id}` | Soft-delete customer |

### Bookings

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/bookings` | List bookings (filter: `?status=...&customer_id=...&date_from=...&date_to=...`) |
| POST | `/bookings` | Create booking (auto-creates transaction, syncs to GHL) |
| GET | `/bookings/{id}` | Get booking with customer, product, transactions |
| PATCH | `/bookings/{id}/status` | Update status (`pending`, `confirmed`, `cancelled`) — syncs to GHL |

### Transactions

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/transactions` | List transactions (filter: `?payment_status=...&payment_method=...&invoice_status=...&customer_id=...&date_from=...&date_to=...`) |
| POST | `/transactions` | Create transaction with line items |
| GET | `/transactions/{id}` | Get transaction with customer, items, booking |
| PATCH | `/transactions/{id}/payment-status` | Update payment status (`draft`, `pending`, `paid`) |
| GET | `/transactions/{id}/invoice` | Generate invoice data |

### Catalog

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/categories` | List categories |
| POST | `/categories` | Create category |
| GET | `/amenities` | List amenities |
| POST | `/amenities` | Create amenity |
| GET | `/features` | List features |
| POST | `/features` | Create feature |

### Settings

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/settings/engage` | Get GoHighLevel (Engage) integration settings |
| POST | `/settings/engage` | Save Engage settings |
| GET | `/settings/countries` | List countries |
| GET | `/settings/custom-fields` | List custom fields (filter: `?entity_type=...`) |
| POST | `/settings/custom-fields` | Create custom field |

## GoHighLevel Integration

Two-way sync with GoHighLevel CRM:

- **Inbound webhooks** (`POST /api/v1/webhooks/ghl`) receive contact/opportunity updates from GHL
- **Outbound sync** (`GhlService` + `GhlClient`) pushes customer and booking data to GHL
- Every webhook payload (inbound and outbound) is logged in the `webhook_logs` table for audit/debugging

### Supported Inbound Events

| Event | Action |
|-------|--------|
| `contact.created` | Create/update customer with `ghl_contact_id` |
| `contact.updated` | Update matching customer |
| `opportunity.created` | Link to latest unlinked booking |
| `opportunity.stage_changed` | Update booking status (`new`→`pending`, `booked`→`confirmed`, `lost`→`cancelled`) |

### Outbound Sync

| Trigger | GHL Action |
|---------|------------|
| Customer created | Create GHL contact |
| Customer updated | Update GHL contact |
| Booking created | Create GHL opportunity |
| Booking status changed | Update GHL opportunity stage |

Configure via Engage Settings (`/settings/engage`):

| Field | Description |
|-------|-------------|
| `location_id` | GHL Location ID (required) |
| `api_key` | GHL API Key (required) |
| `access_token` | OAuth access token |
| `refresh_token` | OAuth refresh token |

## Database

17 tables stored in SQLite (dev) / PostgreSQL (production). All tables use ULID primary keys.

| Table | Description | Soft Delete | Tenant-scoped |
|-------|-------------|:-----------:|:-------------:|
| `users` | System users (admin, staff, cashier) | No | Yes |
| `products` | Unified: campsites, equipment, merchandise, services, add-ons | Yes | Yes |
| `categories` | Product categories (hierarchical via `parent_id`) | No | Yes |
| `amenities` | Campsite amenities (e.g., WiFi, shower) | No | No |
| `features` | Product features | No | No |
| `product_amenities` | Pivot: products ↔ amenities | No | No |
| `product_features` | Pivot: products ↔ features | No | No |
| `product_prices` | Seasonal pricing tiers per product | No | No |
| `product_variations` | Size/type variations per product | No | No |
| `customers` | Customers (synced to GHL via `ghl_contact_id`) | Yes | Yes |
| `bookings` | Booking records | No | Yes |
| `transactions` | Payments/invoices | Yes | Yes |
| `transaction_items` | Line items per transaction | No | No |
| `engage_settings` | GHL integration config per tenant | No | Yes |
| `webhook_logs` | GHL webhook audit trail | No | Optional |
| `custom_fields` | Dynamic custom fields per entity type | No | Yes |
| `countries` | Country list | No | No |

## Architecture

```
Route → Controller → Service → Model
                ↘
           FormRequest (validation)
                ↘
           API Resource (response)
```

Controllers are thin — all business logic lives in dedicated Service classes (`ProductService`, `BookingService`, `TransactionService`, `GhlService`). Form Requests handle validation; API Resources shape JSON responses.

### Key Business Logic

- **Booking creation** auto-calculates `total_amount` from `base_price × nights`, auto-creates a pending transaction, and pushes an opportunity to GHL
- **Payment status update** to `paid` auto-sets `invoice_status` to `completed`
- **Available campsites query** excludes products with overlapping bookings
- **GHL sync failures** are logged but don't block the main operation (try/catch with logging)

## Role-Based Access

User roles: `admin`, `staff`, `cashier`. The `RoleMiddleware` is registered as the `role` alias and can be applied to routes:

```php
Route::middleware(['auth:sanctum', 'role:admin'])->group(function () { ... });
```

## Testing

```bash
composer test          # Run full test suite
php artisan test       # Alternative
./vendor/bin/pint      # Code formatting (PSR-12)
```

Uses in-memory SQLite for tests via PHPUnit 12.

## Response Format

All API responses follow a consistent envelope:

```json
{
  "success": true,
  "data": { ... },
  "message": "Description of what happened."
}
```

Error responses:

```json
{
  "success": false,
  "message": "Error description."
}
```

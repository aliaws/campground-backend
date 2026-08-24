# Postman — Campground POS API

## Import
1. Import `Campground POS API.postman_collection.json`
2. Import `Campground_POS_Local.postman_environment.json`
3. Select environment **Campground POS — Local**

## Role logins (tokens auto-saved)
Open **0. Auth — Login by Role** and run:

| Request | Saves |
|---------|--------|
| Login as Owner | `owner_token` + `active_token` |
| Login as Admin | `admin_token` + `active_token` |
| Login as Staff | `staff_token` + `active_token` |
| Login as Customer | `customer_token` + `active_token` |
| Login as Superadmin | `superadmin_token` + `active_token` |

Most POS/Admin folders use `{{active_token}}` (collection auth).  
**3. Customer Portal** forces `{{customer_token}}`.  
**6. Super Admin** forces `{{superadmin_token}}`.  
**1. Public Website** uses no auth.

## Which role can call what (2026-08-13 RBAC redesign)

`config/permissions.php` (in the backend repo) is the single source of truth this
collection mirrors — every request that changed access as part of that redesign
carries a one-line description naming the roles that can call it. In short:

| Folder | Who | What |
|---|---|---|
| **4. POS Operations** | owner, admin, staff | Day-to-day: read products/services/categories, full customer CRUD (not delete), full booking flow, POS sales, view transactions |
| **5. Admin / Owner** | owner, admin only | Management/deletion/GHL-sync: staff accounts, site maps, Engage settings (identifiers, tokens, refresh, data sync), product/category/amenity/feature writes, customer delete + archive/restore, reports |
| **6. Super Admin** | superadmin only | Genuinely org-less — list/block/unblock organizations, read-only cross-org drill-down (rentals/products/bookings/product transactions), read-only Engage identifiers (no refresh/sync), countries |
| **7. Permissions** | any authenticated role | `GET /permissions` — the full `{action, role, decider}` matrix, no secrets |
| **9. Authorization Regression** | mixed (see each request) | Negative tests — each asserts a specific role gets 403 on an endpoint it should no longer (or never) reach |

A super-admin cannot reach anything in folders 4 or 5 (it isn't in those tiers at
all, by design — see folder 9's "Super-admin cannot access ordinary org-scoped
product list" check). An owner/admin cannot reach folder 6.

## Suggested order
1. Login as Owner → **4. POS Operations** (products, bookings, sales)
2. Login as Owner → **5. Admin / Owner** (staff, maps, Engage settings, deletions)
3. **1. Public Website** (browse rentals / quote / book)
4. **2–3. Customer** account + portal (customer must be `status=active`)
5. Login as Superadmin → **6. Super Admin** (organizations, cross-org read views)
6. **7. Permissions** (any logged-in role)
7. **9. Authorization Regression** — run last, after the logins above have populated `{{customer_id}}`/`{{organization_id}}`/etc.; each request asserts its own expected status

## Notes
- Customer login fails if status is `disabled` / `pending` — activate in DB or complete verify+password first.
- Fill `admin_email` / `admin_password` after creating an admin via Staff Management.
- Fill `superadmin_email` / `superadmin_password` to match whatever `SuperAdminSeeder` was seeded with (`SUPERADMIN_EMAIL`/`SUPERADMIN_PASSWORD` in `.env`).
- Passwords in the Local environment match this machine's seeded users; change them if you rotate credentials.
- Known pre-existing gap, not touched by the 2026-08-13 pass: folder 4's **Transactions** subfolder still points at the pre-2026-08-10 generic `/transactions*` paths, which no longer exist (replaced by `/product-transactions` and `/rental-transactions`, both already present elsewhere in folder 4/5). Flagging rather than fixing — out of scope for the RBAC change.

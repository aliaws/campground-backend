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
**1. Public Website** uses no auth.

## Suggested order
1. Login as Owner → **4. POS Operations** (products, bookings, sales)
2. Login as Owner → **5. Admin / Owner** (staff, maps, Engage settings)
3. **1. Public Website** (browse rentals / quote / book)
4. **2–3. Customer** account + portal (customer must be `status=active`)
5. **6. Super Admin** when SaaS routes exist

## Notes
- Customer login fails if status is `disabled` / `pending` — activate in DB or complete verify+password first.
- Fill `admin_email` / `admin_password` after creating an admin via Staff Management.
- Passwords in the Local environment match this machine’s seeded users; change them if you rotate credentials.

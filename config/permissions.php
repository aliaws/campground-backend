<?php

/**
 * Single source of truth for role -> action gating. Read through
 * App\Support\PermissionMatrix, which is:
 *  - the ENFORCEMENT source for any route using the `permission:<action>`
 *    middleware (App\Http\Middleware\PermissionMiddleware) — not just
 *    documentation the frontend reads, so this file and the API's actual
 *    behavior can't drift apart.
 *  - the source for GET /permissions and UserResource.permissions.allowed_actions,
 *    which the frontend uses instead of hardcoded role checks.
 *
 * Adding a new gated action: add a key here, apply `permission:<key>` on
 * the route (or check PermissionMatrix::allows($user, $key) in a
 * controller for a `role+target`-style per-record check), then add a
 * matching `requires` on the frontend Sidebar item / AppLayout page guard.
 *
 * `decider` is descriptive metadata (surfaced via GET /permissions), not
 * itself enforced by PermissionMatrix — it tells a human/frontend *how*
 * a role's access is actually scoped once the role check passes:
 *   - role          plain role membership, no further scoping
 *   - role+org      role membership AND the record belongs to the actor's
 *                   active organization (enforced by controllers/services
 *                   already filtering on engage_organization_location_id)
 *   - role+platform role membership, org-less — an explicit org id is
 *                   supplied per request (superadmin's drill-down)
 *   - role+target   role membership AND a per-record check in the
 *                   controller (e.g. User::canUpdateStaffUser())
 */
return [

    'roles' => ['superadmin', 'owner', 'admin', 'staff', 'customer'],

    'actions' => [

        // Dashboard
        'dashboard.view' => [
            'group' => 'dashboard', 'label' => 'View the staff dashboard',
            'roles' => ['owner', 'admin'], 'decider' => 'role+org',
        ],
        'reports.view' => [
            'group' => 'dashboard', 'label' => 'View reports',
            'roles' => ['owner', 'admin'], 'decider' => 'role+org',
        ],
        'platform.dashboard.view' => [
            'group' => 'platform', 'label' => 'View the super-admin platform dashboard',
            'roles' => ['superadmin'], 'decider' => 'role',
        ],

        // Organizations (super-admin platform view)
        'organization.list' => [
            'group' => 'organization', 'label' => 'List all organizations',
            'roles' => ['superadmin'], 'decider' => 'role',
        ],
        'organization.view' => [
            'group' => 'organization', 'label' => 'View a single organization',
            'roles' => ['superadmin'], 'decider' => 'role',
        ],
        'organization.block' => [
            'group' => 'organization', 'label' => 'Block an organization',
            'roles' => ['superadmin'], 'decider' => 'role',
        ],
        'organization.unblock' => [
            'group' => 'organization', 'label' => 'Unblock an organization',
            'roles' => ['superadmin'], 'decider' => 'role',
        ],
        'organization.rentals.view' => [
            'group' => 'organization', 'label' => "View an organization's rental products",
            'roles' => ['superadmin'], 'decider' => 'role+platform',
        ],
        'organization.products.view' => [
            'group' => 'organization', 'label' => "View an organization's POS products",
            'roles' => ['superadmin'], 'decider' => 'role+platform',
        ],
        'organization.bookings.view' => [
            'group' => 'organization', 'label' => "View an organization's bookings",
            'roles' => ['superadmin'], 'decider' => 'role+platform',
        ],
        'organization.product_transactions.view' => [
            'group' => 'organization', 'label' => "View an organization's POS product transactions",
            'roles' => ['superadmin'], 'decider' => 'role+platform',
        ],
        'organization.staff.view' => [
            'group' => 'organization', 'label' => "View an organization's staff accounts",
            'roles' => ['superadmin'], 'decider' => 'role+platform',
        ],

        // Engage / GHL settings
        'engage.identifiers.view' => [
            'group' => 'engage', 'label' => 'View Engage identifiers (read-only)',
            'roles' => ['superadmin', 'owner', 'admin'], 'decider' => 'role+org',
            'deciders' => ['superadmin' => 'role+platform'],
        ],
        'engage.identifiers.update' => [
            'group' => 'engage', 'label' => 'Update Engage settings',
            'roles' => ['owner', 'admin'], 'decider' => 'role+org',
        ],
        'engage.tokens.view' => [
            'group' => 'engage', 'label' => 'View Engage tokens',
            'roles' => ['owner', 'admin'], 'decider' => 'role+org',
        ],
        'engage.tokens.update' => [
            'group' => 'engage', 'label' => 'Save Engage tokens',
            'roles' => ['owner', 'admin'], 'decider' => 'role+org',
        ],
        'engage.refresh_token' => [
            'group' => 'engage', 'label' => 'Refresh the Engage access token',
            'roles' => ['owner', 'admin'], 'decider' => 'role+org',
        ],
        'engage.data_sync' => [
            'group' => 'engage', 'label' => 'Trigger a GHL data pull/sync',
            'roles' => ['owner', 'admin'], 'decider' => 'role+org',
        ],
        'engage.sync_log.view' => [
            'group' => 'engage', 'label' => 'View the GHL sync log',
            'roles' => ['owner', 'admin'], 'decider' => 'role+org',
        ],

        // Customers
        'customer.view' => [
            'group' => 'customers', 'label' => 'View customers',
            'roles' => ['owner', 'admin', 'staff'], 'decider' => 'role+org',
        ],
        'customer.create' => [
            'group' => 'customers', 'label' => 'Create a customer',
            'roles' => ['owner', 'admin', 'staff'], 'decider' => 'role+org',
        ],
        'customer.update' => [
            'group' => 'customers', 'label' => 'Update a customer',
            'roles' => ['owner', 'admin', 'staff'], 'decider' => 'role+org',
        ],
        'customer.sync' => [
            'group' => 'customers', 'label' => 'Sync a customer to GHL',
            'roles' => ['owner', 'admin', 'staff'], 'decider' => 'role+org',
        ],
        'customer.delete' => [
            'group' => 'customers', 'label' => 'Delete a customer permanently',
            'roles' => ['owner', 'admin'], 'decider' => 'role+org',
        ],
        'customer.archive.view' => [
            'group' => 'customers', 'label' => 'View archived customers',
            'roles' => ['owner', 'admin'], 'decider' => 'role+org',
        ],
        'customer.archive.restore' => [
            'group' => 'customers', 'label' => 'Restore an archived customer',
            'roles' => ['owner', 'admin'], 'decider' => 'role+org',
        ],
        'customer.bulk_sync' => [
            'group' => 'customers', 'label' => 'Bulk sync/pull customers to/from GHL',
            'roles' => ['owner', 'admin'], 'decider' => 'role+org',
        ],

        // Bookings
        'booking.view' => [
            'group' => 'bookings', 'label' => 'View bookings',
            'roles' => ['owner', 'admin', 'staff'], 'decider' => 'role+org',
        ],
        'booking.create' => [
            'group' => 'bookings', 'label' => 'Create a booking',
            'roles' => ['owner', 'admin', 'staff'], 'decider' => 'role+org',
        ],
        'booking.update_status' => [
            'group' => 'bookings', 'label' => "Update a booking's status",
            'roles' => ['owner', 'admin', 'staff'], 'decider' => 'role+org',
        ],
        'booking.check_in_out' => [
            'group' => 'bookings', 'label' => 'Record booking check-in/out',
            'roles' => ['owner', 'admin', 'staff'], 'decider' => 'role+org',
        ],
        'booking.confirm' => [
            'group' => 'bookings', 'label' => 'Confirm a booking',
            'roles' => ['owner', 'admin', 'staff'], 'decider' => 'role+org',
        ],
        'booking.pay_cash' => [
            'group' => 'bookings', 'label' => 'Record a cash payment on a booking',
            'roles' => ['owner', 'admin', 'staff'], 'decider' => 'role+org',
        ],
        'booking.invoice.view' => [
            'group' => 'bookings', 'label' => 'View a booking invoice',
            'roles' => ['owner', 'admin', 'staff'], 'decider' => 'role+org',
        ],

        // POS / products
        'pos.sell' => [
            'group' => 'pos', 'label' => 'Sell products at the POS',
            'roles' => ['owner', 'admin', 'staff'], 'decider' => 'role+org',
        ],
        'product.view' => [
            'group' => 'pos', 'label' => 'View products',
            'roles' => ['owner', 'admin', 'staff'], 'decider' => 'role+org',
        ],
        'product.manage' => [
            'group' => 'pos', 'label' => 'Create/update/delete products',
            'roles' => ['owner', 'admin'], 'decider' => 'role+org',
        ],
        'product.sync_ghl' => [
            'group' => 'pos', 'label' => 'Sync/pull products to/from GHL',
            'roles' => ['owner', 'admin'], 'decider' => 'role+org',
        ],
        'product_transaction.view' => [
            'group' => 'pos', 'label' => 'View POS product transactions',
            'roles' => ['owner', 'admin', 'staff'], 'decider' => 'role+org',
        ],
        'product_transaction.update_payment_status' => [
            'group' => 'pos', 'label' => 'Update a POS transaction payment status',
            'roles' => ['owner', 'admin', 'staff'], 'decider' => 'role+org',
        ],
        'rental_transaction.view' => [
            'group' => 'pos', 'label' => 'View rental transactions',
            'roles' => ['owner', 'admin', 'staff'], 'decider' => 'role+org',
        ],

        // Catalog: categories
        'category.view' => [
            'group' => 'catalog', 'label' => 'View product categories',
            'roles' => ['owner', 'admin', 'staff'], 'decider' => 'role+org',
        ],
        'category.manage' => [
            'group' => 'catalog', 'label' => 'Manage product categories',
            'roles' => ['owner', 'admin'], 'decider' => 'role+org',
        ],

        // Rental Services (renamed from "Services")
        'service.view' => [
            'group' => 'services', 'label' => 'View rental services',
            'roles' => ['owner', 'admin', 'staff'], 'decider' => 'role+org',
        ],
        'service.manage' => [
            'group' => 'services', 'label' => 'Manage rental services / pull from GHL',
            'roles' => ['owner', 'admin'], 'decider' => 'role+org',
        ],
        'service_category.view' => [
            'group' => 'services', 'label' => 'View rental service categories',
            'roles' => ['owner', 'admin', 'staff'], 'decider' => 'role+org',
        ],
        'service_category.manage' => [
            'group' => 'services', 'label' => 'Manage rental service categories',
            'roles' => ['owner', 'admin'], 'decider' => 'role+org',
        ],
        'amenity.view' => [
            'group' => 'services', 'label' => 'View amenities',
            'roles' => ['owner', 'admin', 'staff'], 'decider' => 'role+org',
        ],
        'amenity.manage' => [
            'group' => 'services', 'label' => 'Manage amenities',
            'roles' => ['owner', 'admin'], 'decider' => 'role+org',
        ],
        'feature.view' => [
            'group' => 'services', 'label' => 'View features',
            'roles' => ['owner', 'admin', 'staff'], 'decider' => 'role+org',
        ],
        'feature.manage' => [
            'group' => 'services', 'label' => 'Manage features',
            'roles' => ['owner', 'admin'], 'decider' => 'role+org',
        ],

        // Site maps
        'site_map.view' => [
            'group' => 'maps', 'label' => 'View the campsite map',
            'roles' => ['owner', 'admin', 'staff'], 'decider' => 'role+org',
        ],
        'site_map.manage' => [
            'group' => 'maps', 'label' => 'Edit the campsite map',
            'roles' => ['owner', 'admin'], 'decider' => 'role+org',
        ],

        // Staff management — org-scoped only; super-admin's view is
        // organization.staff.view instead (reached via an org's own
        // drill-down page, not a flat cross-org /staff list).
        'staff.view' => [
            'group' => 'staff', 'label' => 'View staff accounts',
            'roles' => ['owner', 'admin'], 'decider' => 'role+org',
        ],
        'staff.create' => [
            'group' => 'staff', 'label' => 'Create a staff account',
            'roles' => ['owner', 'admin'], 'decider' => 'role+org',
        ],
        'staff.update' => [
            'group' => 'staff', 'label' => 'Update a staff account',
            'roles' => ['owner', 'admin'], 'decider' => 'role+target',
        ],
        'staff.delete' => [
            'group' => 'staff', 'label' => 'Delete a staff account',
            'roles' => ['owner', 'admin'], 'decider' => 'role+target',
        ],

        // Platform-level config (super-admin only)
        'country.view' => [
            'group' => 'config', 'label' => 'View countries reference data',
            'roles' => ['superadmin'], 'decider' => 'role',
        ],
        'webhook.settings.view' => [
            'group' => 'config', 'label' => 'View webhook settings',
            'roles' => ['superadmin'], 'decider' => 'role',
        ],

        // Org-level config (owner/admin)
        'custom_field.view' => [
            'group' => 'config', 'label' => 'View custom fields',
            'roles' => ['owner', 'admin'], 'decider' => 'role+org',
        ],
        'custom_field.manage' => [
            'group' => 'config', 'label' => 'Manage custom fields',
            'roles' => ['owner', 'admin'], 'decider' => 'role+org',
        ],

        // Meta
        'permission.matrix.view' => [
            'group' => 'meta', 'label' => 'View the permission matrix',
            'roles' => ['superadmin', 'owner', 'admin', 'staff', 'customer'], 'decider' => 'role',
        ],
    ],
];

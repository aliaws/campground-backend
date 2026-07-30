<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable([
    'name',
    'email',
    'password',
    'roles',
    'status',
    'jwt_version',
    'customer_id',
    'created_by',
])]
#[Hidden([
    'password',
])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_EXPIRED = 'expired';

    public const ROLE_SUPERADMIN = 'superadmin';

    public const ROLE_OWNER = 'owner';

    public const ROLE_ADMIN = 'admin';

    public const ROLE_STAFF = 'staff';

    public const ROLE_CUSTOMER = 'customer';

    /**
     * Role meanings (do not mix):
     *  - superadmin = SaaS platform owner (no location)
     *  - owner      = Engage location owner (exactly one per location)
     *  - admin      = location admin
     *  - staff      = location staff
     *  - customer   = guest portal
     */

    /** Roles that can access the staff POS/API (not customer-portal-only). */
    public const STAFF_ROLES = [
        self::ROLE_SUPERADMIN,
        self::ROLE_OWNER,
        self::ROLE_ADMIN,
        self::ROLE_STAFF,
    ];

    /** Roles treated as "admin-level" for nav / settings UI. */
    public const ADMIN_ROLES = [
        self::ROLE_SUPERADMIN,
        self::ROLE_OWNER,
        self::ROLE_ADMIN,
    ];

    /** Location-scoped roles assignable via Staff API (never superadmin/owner). */
    public const ASSIGNABLE_STAFF_ROLES = [
        self::ROLE_ADMIN,
        self::ROLE_STAFF,
    ];

    /** @return list<string> */
    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_ACTIVE,
            self::STATUS_DISABLED,
            self::STATUS_BLOCKED,
            self::STATUS_EXPIRED,
        ];
    }

    /** @return list<string> */
    public static function availableRoles(): array
    {
        return [
            self::ROLE_SUPERADMIN,
            self::ROLE_OWNER,
            self::ROLE_ADMIN,
            self::ROLE_STAFF,
            self::ROLE_CUSTOMER,
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function locationLinks(): HasMany
    {
        return $this->hasMany(UserLocation::class);
    }

    public function organizationLocations(): BelongsToMany
    {
        return $this->belongsToMany(
            EngageOrganizationLocation::class,
            'users_locations',
            'user_id',
            'engage_organization_location_id'
        )->withTimestamps();
    }

    /** First linked location id, else system default. */
    public function primaryLocationId(): ?string
    {
        $fromJunction = $this->relationLoaded('locationLinks')
            ? $this->locationLinks->first()?->engage_organization_location_id
            : $this->locationLinks()->value('engage_organization_location_id');

        if (is_string($fromJunction) && $fromJunction !== '') {
            return $fromJunction;
        }

        try {
            return \App\Services\OrganizationLocationResolver::resolveDefaultLocationId();
        } catch (\Throwable) {
            return null;
        }
    }

    public function belongsToLocation(string $locationId): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        if ($this->relationLoaded('locationLinks')) {
            return $this->locationLinks->contains('engage_organization_location_id', $locationId);
        }

        return $this->locationLinks()
            ->where('engage_organization_location_id', $locationId)
            ->exists();
    }

    public function attachLocation(string $locationId): void
    {
        UserLocation::query()->firstOrCreate([
            'user_id' => $this->id,
            'engage_organization_location_id' => $locationId,
        ]);
    }

    /**
     * Active Engage organization location for scoping business data.
     */
    public function resolveOrganizationLocationId(): string
    {
        $id = $this->primaryLocationId();
        if (! is_string($id) || $id === '') {
            throw new \RuntimeException('User is not linked to an organization location.');
        }

        return $id;
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(self::class, 'created_by');
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(UserVerification::class);
    }

    /** @return list<string> */
    public function roleList(): array
    {
        $roles = $this->roles ?? [];

        return array_values(array_filter(
            is_array($roles) ? $roles : [],
            fn ($r) => is_string($r) && $r !== ''
        ));
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roleList(), true);
    }

    public function hasAnyRole(string ...$roles): bool
    {
        return array_intersect($this->roleList(), $roles) !== [];
    }

    public function primaryRole(): ?string
    {
        $roles = $this->roleList();

        return $roles[0] ?? null;
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(self::ROLE_SUPERADMIN);
    }

    /** Location owner — not the SaaS superadmin. */
    public function isLocationOwner(): bool
    {
        return $this->hasRole(self::ROLE_OWNER) && ! $this->isSuperAdmin();
    }

    public function isAdmin(): bool
    {
        return $this->hasAnyRole(...self::ADMIN_ROLES);
    }

    public function isStaff(): bool
    {
        return $this->hasRole(self::ROLE_STAFF);
    }

    public function isCustomer(): bool
    {
        return $this->hasRole(self::ROLE_CUSTOMER);
    }

    public function isActiveCustomerAccount(): bool
    {
        return $this->isCustomer() && $this->status === self::STATUS_ACTIVE;
    }

    public function isLoginBlocked(): bool
    {
        return in_array($this->status, [
            self::STATUS_DISABLED,
            self::STATUS_BLOCKED,
            self::STATUS_EXPIRED,
        ], true);
    }

    public function canManageStaffUsers(): bool
    {
        return $this->hasAnyRole(self::ROLE_SUPERADMIN, self::ROLE_OWNER, self::ROLE_ADMIN);
    }

    /** @return list<string> */
    public function assignableStaffRoles(): array
    {
        if ($this->isSuperAdmin() || $this->isLocationOwner()) {
            return self::ASSIGNABLE_STAFF_ROLES;
        }

        if ($this->hasRole(self::ROLE_ADMIN)) {
            return [self::ROLE_STAFF];
        }

        return [];
    }

    public function canAssignRole(string $role): bool
    {
        return in_array($role, $this->assignableStaffRoles(), true);
    }

    public function canUpdateStaffUser(self $target): bool
    {
        if ($this->id === $target->id) {
            return false;
        }

        if ($target->isSuperAdmin() || $target->isLocationOwner()) {
            return $this->isSuperAdmin();
        }

        if ($this->isSuperAdmin() || $this->isLocationOwner()) {
            return $target->hasAnyRole(self::ROLE_ADMIN, self::ROLE_STAFF);
        }

        if ($this->hasRole(self::ROLE_ADMIN)) {
            // Admin may update other non-admin users (staff)
            return $target->hasRole(self::ROLE_STAFF) && ! $target->hasRole(self::ROLE_ADMIN);
        }

        return false;
    }

    public function canDeleteStaffUser(self $target): bool
    {
        if ($this->id === $target->id) {
            return false;
        }

        if ($target->isSuperAdmin() || $target->isLocationOwner()) {
            return false;
        }

        if ($this->isSuperAdmin() || $this->isLocationOwner()) {
            return $target->hasAnyRole(self::ROLE_ADMIN, self::ROLE_STAFF);
        }

        if ($this->hasRole(self::ROLE_ADMIN)) {
            // Admin cannot delete admin — only other users (staff)
            return $target->hasRole(self::ROLE_STAFF) && ! $target->hasRole(self::ROLE_ADMIN);
        }

        return false;
    }

    public static function createdByLabel(?self $user, string $fallbackName): string
    {
        if (! $user) {
            return "Customer - {$fallbackName}";
        }

        $bucket = $user->isSuperAdmin()
            ? 'Superadmin'
            : ($user->isLocationOwner() || $user->hasRole(self::ROLE_ADMIN) ? 'Admin' : 'Staff');

        return "{$bucket} - {$user->name}";
    }

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'roles' => 'array',
        ];
    }
}

<?php

namespace App\Support;

use App\Models\User;
use RuntimeException;

/**
 * Reads config/permissions.php — see that file's header comment for the
 * full design rationale (why a config file and not a database table, what
 * `decider` means). This class is the only thing that should read that
 * config directly; PermissionMiddleware and GET /permissions both go
 * through here.
 */
class PermissionMatrix
{
    /** @return list<string> */
    public static function roles(): array
    {
        return config('permissions.roles', []);
    }

    /** @return array<string, array{group: string, label: string, roles: list<string>, decider: string, deciders?: array<string,string>}> */
    public static function actions(): array
    {
        return config('permissions.actions', []);
    }

    /**
     * @return list<string>
     *
     * @throws RuntimeException if $action isn't a known key — a typo in a
     *                           `permission:<action>` route middleware string
     *                           becomes a loud failure, not a silent hole.
     */
    public static function rolesFor(string $action): array
    {
        $entry = self::actions()[$action] ?? null;

        if ($entry === null) {
            throw new RuntimeException("Unknown permission action: {$action}");
        }

        return $entry['roles'];
    }

    public static function deciderFor(string $action, string $role): string
    {
        $entry = self::actions()[$action] ?? null;

        if ($entry === null) {
            throw new RuntimeException("Unknown permission action: {$action}");
        }

        return $entry['deciders'][$role] ?? $entry['decider'];
    }

    public static function allows(?User $user, string $action): bool
    {
        if (! $user) {
            return false;
        }

        return $user->hasAnyRole(...self::rolesFor($action));
    }

    /** Every action key this user's role(s) are allowed to perform. */
    public static function allowedFor(User $user): array
    {
        $allowed = [];

        foreach (self::actions() as $action => $entry) {
            if ($user->hasAnyRole(...$entry['roles'])) {
                $allowed[] = $action;
            }
        }

        return $allowed;
    }

    /**
     * The full {action, role, decider} matrix, shaped for GET /permissions:
     * every action, every role, whether that role is allowed, and how.
     */
    public static function matrix(): array
    {
        $roles = self::roles();
        $rows = [];

        foreach (self::actions() as $action => $entry) {
            $perRole = [];

            foreach ($roles as $role) {
                $allowed = in_array($role, $entry['roles'], true);
                $perRole[$role] = [
                    'allowed' => $allowed,
                    'decider' => $allowed ? ($entry['deciders'][$role] ?? $entry['decider']) : 'denied',
                ];
            }

            $rows[] = [
                'action' => $action,
                'group' => $entry['group'],
                'label' => $entry['label'],
                'roles' => $perRole,
            ];
        }

        return $rows;
    }
}

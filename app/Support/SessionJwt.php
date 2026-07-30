<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Stateless access JWTs for API login (Bearer). Revocation via jwt_version on
 * users + optional per-jti Redis blacklist on logout.
 */
final class SessionJwt
{
    public const TYP = 'access';

    public static function issue(User $user): string
    {
        $ttlMinutes = (int) config('jwt.ttl_minutes', 60 * 24 * 7);
        $now = time();
        $jti = (string) Str::uuid();

        return Jwt::encode([
            'sub' => (string) $user->id,
            'typ' => self::TYP,
            'jti' => $jti,
            'ver' => (int) ($user->jwt_version ?? 0),
            'iat' => $now,
            'exp' => $now + ($ttlMinutes * 60),
        ]);
    }

    /**
     * @return array{user: User, jti: string, exp: int}|null
     */
    public static function authenticate(string $jwt): ?array
    {
        $claims = Jwt::decode($jwt);
        if (! $claims || ($claims['typ'] ?? null) !== self::TYP) {
            return null;
        }

        $jti = $claims['jti'] ?? null;
        $sub = $claims['sub'] ?? null;
        if (! is_string($jti) || $jti === '' || ! is_string($sub) || $sub === '') {
            return null;
        }

        try {
            if (Cache::has(self::blacklistKey($jti))) {
                return null;
            }
        } catch (\Throwable) {
            // Cache store unavailable — skip blacklist check rather than 500ing auth.
        }

        $user = User::query()->find($sub);
        if (! $user) {
            return null;
        }

        if ((int) ($claims['ver'] ?? -1) !== (int) ($user->jwt_version ?? 0)) {
            return null;
        }

        if ($user->isLoginBlocked()) {
            return null;
        }

        return [
            'user' => $user,
            'jti' => $jti,
            'exp' => (int) ($claims['exp'] ?? 0),
        ];
    }

    /** Blacklist this jti until its natural expiry (logout). */
    public static function revoke(string $jti, int $exp): void
    {
        $ttl = max(1, $exp - time());
        try {
            Cache::put(self::blacklistKey($jti), true, $ttl);
        } catch (\Throwable) {
            // Best-effort; jwt_version bumps still revoke-all when needed.
        }
    }

    /** Invalidate every outstanding access JWT for this user (password reset, staff delete, …). */
    public static function revokeAllFor(User $user): void
    {
        $user->jwt_version = (int) ($user->jwt_version ?? 0) + 1;
        $user->save();
    }

    private static function blacklistKey(string $jti): string
    {
        return 'jwt:revoke:'.$jti;
    }
}

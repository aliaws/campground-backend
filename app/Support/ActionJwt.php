<?php

namespace App\Support;

/**
 * Minimal HS256 JWT for one-time customer action links (email verification,
 * password reset). Validated against user_verifications.jti.
 */
final class ActionJwt
{
    /**
     * @param  array<string, mixed>  $claims  Must include jti + exp; typ/sub recommended.
     */
    public static function encode(array $claims): string
    {
        return Jwt::encode($claims);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function decode(string $jwt): ?array
    {
        return Jwt::decode($jwt);
    }
}

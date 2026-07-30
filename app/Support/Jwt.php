<?php

namespace App\Support;

/**
 * Minimal HS256 JWT encode/decode (APP_KEY). Used by session auth and action links.
 */
final class Jwt
{
    /**
     * @param  array<string, mixed>  $claims
     */
    public static function encode(array $claims): string
    {
        $header = self::b64(['alg' => 'HS256', 'typ' => 'JWT']);
        $payload = self::b64($claims);
        $signature = self::sign("{$header}.{$payload}");

        return "{$header}.{$payload}.{$signature}";
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function decode(string $jwt): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }

        [$header, $payload, $signature] = $parts;

        if (! hash_equals(self::sign("{$header}.{$payload}"), $signature)) {
            return null;
        }

        $claims = json_decode(self::ub64($payload), true);
        if (! is_array($claims)) {
            return null;
        }

        if (isset($claims['exp']) && (int) $claims['exp'] < time()) {
            return null;
        }

        return $claims;
    }

    /**
     * Read JWT payload without verifying the signature (e.g. GHL access tokens
     * signed with their key). For display/debug only — never trust for auth.
     *
     * @return array<string, mixed>|null
     */
    public static function peekPayload(string $jwt): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }

        $claims = json_decode(self::ub64($parts[1]), true);

        return is_array($claims) ? $claims : null;
    }

    private static function sign(string $data): string
    {
        return self::b64Raw(hash_hmac('sha256', $data, self::secret(), true));
    }

    private static function secret(): string
    {
        $key = (string) config('app.key');

        if (str_starts_with($key, 'base64:')) {
            return base64_decode(substr($key, 7)) ?: $key;
        }

        return $key;
    }

    /** @param  array<string, mixed>  $data */
    private static function b64(array $data): string
    {
        return self::b64Raw(json_encode($data, JSON_THROW_ON_ERROR));
    }

    private static function b64Raw(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function ub64(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        return (string) base64_decode(strtr($data, '-_', '+/'));
    }
}

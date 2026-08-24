<?php

namespace App\Auth;

use App\Support\SessionJwt;
use Illuminate\Auth\GuardHelpers;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Http\Request;

class JwtGuard implements Guard
{
    use GuardHelpers;

    private ?string $jti = null;

    private ?int $tokenExp = null;

    private ?string $resolvedForToken = null;

    public function __construct(
        UserProvider $provider,
    ) {
        $this->provider = $provider;
    }

    public function user()
    {
        $token = $this->request()->bearerToken();

        // Re-resolve when the Bearer token changes (or first call).
        if ($this->user !== null && $this->resolvedForToken === $token) {
            return $this->user;
        }

        $this->user = null;
        $this->jti = null;
        $this->tokenExp = null;
        $this->resolvedForToken = $token;

        if (! $token) {
            return null;
        }

        $auth = SessionJwt::authenticate($token);
        if (! $auth) {
            return null;
        }

        $this->jti = $auth['jti'];
        $this->tokenExp = $auth['exp'];
        $this->user = $auth['user'];
        $this->user->setActiveLocationId($auth['loc'] ?? null);

        return $this->user;
    }

    public function validate(array $credentials = [])
    {
        return false;
    }

    public function currentJti(): ?string
    {
        $this->user();

        return $this->jti;
    }

    public function currentTokenExp(): ?int
    {
        $this->user();

        return $this->tokenExp;
    }

    private function request(): Request
    {
        return app('request');
    }
}

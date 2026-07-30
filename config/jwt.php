<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Access JWT TTL (minutes)
    |--------------------------------------------------------------------------
    |
    | Stateless Bearer tokens issued at login / create-password. Logout blacklists
    | the jti in cache until this expiry; password reset bumps users.jwt_version.
    |
    */

    'ttl_minutes' => (int) env('JWT_TTL_MINUTES', 60 * 24 * 7),

];

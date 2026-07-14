<?php

return [
    'verification_ttl_minutes' => (int) env('GUEST_VERIFICATION_TTL_MINUTES', 30),
    'password_reset_ttl_minutes' => (int) env('GUEST_PASSWORD_RESET_TTL_MINUTES', 60),
    'max_verification_attempts' => (int) env('GUEST_MAX_VERIFICATION_ATTEMPTS', 5),
];

<?php

return [
    'verification_ttl_minutes' => (int) env('CUSTOMER_VERIFICATION_TTL_MINUTES', 30),
    'password_reset_ttl_minutes' => (int) env('CUSTOMER_PASSWORD_RESET_TTL_MINUTES', 60),
    'max_verification_attempts' => (int) env('CUSTOMER_MAX_VERIFICATION_ATTEMPTS', 5),
];

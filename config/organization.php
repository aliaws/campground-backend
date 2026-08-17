<?php

return [
    'verification_ttl_minutes' => (int) env('ORGANIZATION_VERIFICATION_TTL_MINUTES', 30),
    'max_verification_attempts' => (int) env('ORGANIZATION_MAX_VERIFICATION_ATTEMPTS', 5),
];

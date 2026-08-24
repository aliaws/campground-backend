<?php

return [
    'verification_ttl_minutes' => (int) env('ORGANIZATION_VERIFICATION_TTL_MINUTES', 30),
    'max_verification_attempts' => (int) env('ORGANIZATION_MAX_VERIFICATION_ATTEMPTS', 5),

    // Rate limits for the public "Register for Application" flow
    // (AppServiceProvider::boot() reads these), tunable via .env without a
    // code change/redeploy — bump these directly if real installs (or
    // testing) are getting throttled.
    'rate_limits' => [
        // POST /public/engage/organizations/register — one location id
        // submission per hit.
        'register_per_hour' => (int) env('ORGANIZATION_REGISTER_MAX_PER_HOUR', 30),
        // POST /public/engage/organizations/{organization}/complete —
        // separate bucket from register above, since a real user often
        // retries this step (typos, picking the country, etc.) more than
        // the one-shot register step.
        'complete_per_hour' => (int) env('ORGANIZATION_COMPLETE_MAX_PER_HOUR', 30),
        'resend_per_hour' => (int) env('ORGANIZATION_RESEND_MAX_PER_HOUR', 10),
        // Shared by verify-code and create-password, mirrors
        // customer-verify's own convention of covering both steps.
        'verify_per_minute' => (int) env('ORGANIZATION_VERIFY_MAX_PER_MINUTE', 20),
    ],
];

<?php

return [
    'support_email' => env('SUPPORT_EMAIL'),
    'operations_email' => env('OPERATIONS_EMAIL'),
    'registration_notify_email' => env('REGISTRATION_NOTIFY_EMAIL'),
    'registration_notify_fallback_email' => env('REGISTRATION_NOTIFY_FALLBACK_EMAIL'),
    'frontend_url' => env('FRONTEND_URL', env('APP_URL', 'http://localhost:5173')),
];

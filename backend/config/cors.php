<?php

// Who may call the API from a browser (docs/specs/mobile-app-spec.md, M4).
// Unset, any origin may (as before this file, Laravel's default) - the web and
// the phone app on every dev host keep working. In production set
// APP_CLIENT_ORIGINS to the web's and the app's addresses, comma separated.
$origins = array_values(array_filter(array_map('trim', explode(',', (string) env('APP_CLIENT_ORIGINS', '')))));

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => $origins ?: ['*'],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    // Downloads (report PDFs) carry their file name here; the app reads it.
    'exposed_headers' => ['Content-Disposition'],
    'max_age' => 0,
    'supports_credentials' => false,
];

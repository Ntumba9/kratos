<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing
|--------------------------------------------------------------------------
|
| KRATOS COMMAND is served from the same origin as the API (via Nginx), so
| browsers need no CORS grants at all. Only origins listed explicitly in
| CORS_ALLOWED_ORIGINS (comma separated) are allowed; the default is none.
| Programmatic clients (tokens, ingestion) are not browsers and are
| unaffected by CORS.
|
*/

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Content-Type', 'X-Requested-With', 'X-XSRF-TOKEN', 'X-Request-Id', 'Authorization'],

    'exposed_headers' => ['X-Request-Id', 'Retry-After'],

    'max_age' => 600,

    'supports_credentials' => true,

];

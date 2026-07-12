<?php

return [

    /*
     * The learner app (native and web) talks to the API with a bearer token —
     * never a cookie — so credentials are off and any origin is safe. In
     * production, pin the origins with CORS_ALLOWED_ORIGINS (comma-separated)
     * rather than shipping '*'.
     */

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', '*')))
    )),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    // The content endpoint answers 304 to an If-None-Match, so the web client
    // must be allowed to read the ETag it caches.
    'exposed_headers' => ['ETag'],

    'max_age' => 0,

    // Bearer tokens, not cookies: no credentialed CORS, so '*' origins stay legal.
    'supports_credentials' => false,

];

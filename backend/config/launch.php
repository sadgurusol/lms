<?php

return [
    /*
     * The `aud` a custom-JWT launch token must name. A token addressed to some
     * other service must not be replayable against this one.
     */
    'audience' => env('LAUNCH_AUDIENCE', env('APP_URL').'/api/v1/launch'),

    /* Where /l/{ticket} sends a browser when the app is not installed. */
    'web_fallback_url' => env('LAUNCH_WEB_FALLBACK', env('APP_URL').'/app'),

    /*
     * The `aud` a partner (client) API token must name — the read-only catalogue
     * a client browses before mapping a course. Distinct from the launch audience.
     */
    'partner_audience' => env('LAUNCH_PARTNER_AUDIENCE', env('APP_URL').'/api/v1/partner'),
];

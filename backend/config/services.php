<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'mux' => [
        'token_id' => env('MUX_TOKEN_ID'),
        'token_secret' => env('MUX_TOKEN_SECRET'),
    ],

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5'),
        // Output ceiling for course generation. A whole-course outline with
        // teaching text is large; too low a cap truncates the JSON mid-reply.
        'generation_max_tokens' => (int) env('GENERATION_MAX_TOKENS', 16000),
        // Force IPv4 when calling the API. Many hosts (notably DigitalOcean
        // droplets) advertise IPv6 to api.anthropic.com but can't route it, so
        // curl stalls on the AAAA address and times out (cURL error 28). Set
        // ANTHROPIC_FORCE_IPV4=false only if you truly need IPv6.
        'force_ipv4' => filter_var(env('ANTHROPIC_FORCE_IPV4', true), FILTER_VALIDATE_BOOL),
    ],

    'voyage' => [
        'key' => env('VOYAGE_API_KEY'),
        'model' => env('VOYAGE_MODEL', 'voyage-3'),
    ],

];

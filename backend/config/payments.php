<?php

return [
    'gateway' => env('PAYMENT_GATEWAY', 'razorpay'),

    'razorpay' => [
        'key_id' => env('RAZORPAY_KEY_ID'),
        'key_secret' => env('RAZORPAY_KEY_SECRET'),
        'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),
    ],

    /*
     * OPEN DECISION (docs/08 §D4): does the Flutter app sell subscriptions
     * in-app? Apple and Google take 15–30% of digital content sold inside the
     * app, and the reader-app exemption holds only if you never link to your own
     * purchase flow from within it.
     *
     * The `subscriptions.provider` check constraint already permits 'apple' and
     * 'google'. Their receipt verification is server-side against the App Store
     * Server API / Play Developer API, driven by *server notifications* — never
     * by a receipt the client hands you. Until that decision is made, only the
     * web (Razorpay) path exists.
     */
    'in_app_purchase_enabled' => false,
];

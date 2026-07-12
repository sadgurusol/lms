<?php

return [

    /*
     * Server-side rendering. Off: the studio sits behind a login, so first-paint
     * time does not justify a Node render process in the deployment.
     */
    'ssr' => [
        'enabled' => false,
    ],

    /*
     * Where a page component lives.
     *
     * Inertia's view finder uses this to fail fast on a typo'd
     * `Inertia::render('Dashbaord')`, and to back `assertInertia()->component()`
     * in tests. Our pages are `.tsx` under the studio entrypoint (docs/13 §2);
     * without these paths every page reads as missing.
     */
    'pages' => [
        'paths' => [
            resource_path('js/studio/pages'),
        ],

        'extensions' => ['tsx'],
    ],

    'testing' => [
        'ensure_pages_exist' => true,
    ],

];

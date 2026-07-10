<?php

return [
    /*
     * Which provider transcodes video.
     *
     * 'local' does not transcode: the asset stays in `processing` until
     * something calls MarkMediaReady. That is deliberate — a dev environment
     * where video is instantly playable hides every bug in the not-yet-ready
     * paths, which is precisely where authors will live.
     */
    'transcoder' => env('MEDIA_TRANSCODER', 'local'),

    'webhook_secret' => env('MEDIA_WEBHOOK_SECRET'),

    /* Signed playback URLs expire fast; paid content must not become a public CDN. */
    'playback_url_ttl' => (int) env('MEDIA_PLAYBACK_TTL', 3600),
];

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Media\MarkMediaReady;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Mux calls this when a transcode finishes (or fails). Verified by an HMAC over
 * the raw body, then applied idempotently — Mux retries, so a replayed
 * "asset.ready" must not clobber a later state (see {@see MarkMediaReady}).
 */
class MediaWebhookController extends Controller
{
    /** Reject a timestamp older than this to blunt replay attacks. */
    private const TOLERANCE_SECONDS = 300;

    public function __construct(private readonly MarkMediaReady $media) {}

    public function mux(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();

        if (! $this->verify($rawBody, (string) $request->header('Mux-Signature', ''))) {
            return response()->json(['error' => 'invalid signature'], 401);
        }

        /** @var array<string, mixed> $event */
        $event = $request->json()->all();
        $type = (string) ($event['type'] ?? '');
        /** @var array<string, mixed> $asset */
        $asset = is_array($event['data'] ?? null) ? $event['data'] : [];
        $assetId = (string) ($asset['id'] ?? '');

        if ($assetId === '') {
            return response()->json(['error' => 'no asset id'], 422);
        }

        $duration = data_get($asset, 'duration');

        try {
            match ($type) {
                'video.asset.ready' => $this->media->ready(
                    'mux',
                    $assetId,
                    (string) data_get($asset, 'playback_ids.0.id', ''),
                    is_numeric($duration) ? (int) round((float) $duration) : null,
                ),
                'video.asset.errored' => $this->media->failed(
                    'mux',
                    $assetId,
                    (string) data_get($asset, 'errors.messages.0', 'transcode failed'),
                ),
                // Any other event (created, preparing, …) is acknowledged, not acted on.
                default => null,
            };
        } catch (RuntimeException) {
            // Unknown asset: acknowledge so Mux stops retrying a webhook for an
            // asset we have no record of (e.g. one created outside this app).
            return response()->json(['status' => 'ignored'], 202);
        }

        return response()->json(['status' => 'ok'], 200);
    }

    /**
     * Mux signs `t=<unix>,v1=<hmac>` where the HMAC is SHA-256 over "<t>.<body>".
     */
    private function verify(string $rawBody, string $header): bool
    {
        $secret = (string) config('media.webhook_secret');
        if ($secret === '' || $header === '') {
            return false;
        }

        $parts = [];
        foreach (explode(',', $header) as $pair) {
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            $parts[$k] = $v;
        }

        $timestamp = $parts['t'] ?? '';
        $signature = $parts['v1'] ?? '';

        if ($timestamp === '' || $signature === '') {
            return false;
        }

        if (abs(time() - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);

        return hash_equals($expected, $signature);
    }
}

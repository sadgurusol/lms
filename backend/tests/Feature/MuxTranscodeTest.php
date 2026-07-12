<?php

use App\Models\Media;
use App\Services\Media\MuxTranscodeProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config(['media.webhook_secret' => 'whsec_test_secret']);
});

/** Build a Mux-style signature header over "<t>.<body>". */
function muxSignature(string $body, ?int $timestamp = null, string $secret = 'whsec_test_secret'): string
{
    $timestamp ??= time();
    $hmac = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

    return "t={$timestamp},v1={$hmac}";
}

/**
 * @param  array<string, mixed>  $payload
 * @return TestResponse<\Illuminate\Http\JsonResponse>
 */
function postMux(array $payload, ?string $signature = null): TestResponse
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);

    return test()->call(
        'POST',
        '/api/v1/webhooks/mux',
        content: $body,
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_MUX_SIGNATURE' => $signature ?? muxSignature($body),
        ],
    );
}

it('marks media ready from a signed asset.ready webhook', function () {
    $media = Media::factory()->video()->processing()->create([
        'provider' => 'mux', 'provider_asset_id' => 'asset-1',
    ]);

    postMux([
        'type' => 'video.asset.ready',
        'data' => [
            'id' => 'asset-1',
            'duration' => 412.7,
            'playback_ids' => [['id' => 'pb-xyz', 'policy' => 'public']],
        ],
    ])->assertOk();

    $media->refresh();
    expect($media->status)->toBe(Media::STATUS_READY)
        ->and($media->playback_id)->toBe('pb-xyz')
        ->and($media->duration_s)->toBe(413);
});

it('records a transcode failure from an errored webhook', function () {
    $media = Media::factory()->video()->processing()->create([
        'provider' => 'mux', 'provider_asset_id' => 'asset-2',
    ]);

    postMux([
        'type' => 'video.asset.errored',
        'data' => ['id' => 'asset-2', 'errors' => ['messages' => ['unsupported codec']]],
    ])->assertOk();

    expect($media->refresh()->status)->toBe(Media::STATUS_FAILED)
        ->and($media->meta['failure_reason'])->toBe('unsupported codec');
});

it('rejects a webhook with a bad signature', function () {
    $media = Media::factory()->video()->processing()->create([
        'provider' => 'mux', 'provider_asset_id' => 'asset-3',
    ]);

    postMux(
        ['type' => 'video.asset.ready', 'data' => ['id' => 'asset-3', 'playback_ids' => [['id' => 'x']]]],
        signature: 't='.time().',v1=deadbeef',
    )->assertStatus(401);

    expect($media->refresh()->status)->toBe(Media::STATUS_PROCESSING);
});

it('rejects a webhook whose timestamp is outside tolerance', function () {
    $body = json_encode(['type' => 'video.asset.ready', 'data' => ['id' => 'a']], JSON_THROW_ON_ERROR);

    postMux(
        ['type' => 'video.asset.ready', 'data' => ['id' => 'a', 'playback_ids' => [['id' => 'x']]]],
        signature: muxSignature($body, time() - 3600),
    )->assertStatus(401);
});

it('acknowledges a replayed ready webhook without changing state', function () {
    $media = Media::factory()->video()->processing()->create([
        'provider' => 'mux', 'provider_asset_id' => 'asset-4',
    ]);

    $payload = [
        'type' => 'video.asset.ready',
        'data' => ['id' => 'asset-4', 'duration' => 100, 'playback_ids' => [['id' => 'first']]],
    ];
    postMux($payload)->assertOk();

    $replay = [
        'type' => 'video.asset.ready',
        'data' => ['id' => 'asset-4', 'duration' => 999, 'playback_ids' => [['id' => 'DIFFERENT']]],
    ];
    postMux($replay)->assertOk();

    expect($media->refresh()->playback_id)->toBe('first')
        ->and($media->duration_s)->toBe(100);
});

it('acknowledges a webhook for an unknown asset instead of erroring', function () {
    postMux([
        'type' => 'video.asset.ready',
        'data' => ['id' => 'who-dis', 'playback_ids' => [['id' => 'x']]],
    ])->assertStatus(202);
});

it('submits an asset to the Mux API and returns its asset id', function () {
    config(['media.transcoder' => 'mux', 'filesystems.default' => 's3']);

    Http::fake([
        'api.mux.com/*' => Http::response(['data' => ['id' => 'mux-asset-99']], 201),
    ]);

    // A stub disk that can issue a temporary read URL (S3 can; local cannot).
    $media = Media::factory()->video()->processing()->create();
    Storage::shouldReceive('disk->temporaryUrl')->andReturn('https://bucket.test/object?sig=stub');

    expect(app(MuxTranscodeProvider::class)->submit($media))->toBe('mux-asset-99');

    Http::assertSent(fn ($request) => $request->url() === 'https://api.mux.com/video/v1/assets'
        && $request['input'][0]['url'] === 'https://bucket.test/object?sig=stub'
        && $request['passthrough'] === $media->id);
});

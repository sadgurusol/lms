<?php

use App\Models\Media;
use App\Models\User;
use App\Services\Media\CompleteMediaUpload;
use App\Services\Media\MarkMediaReady;
use App\Services\Media\RequestMediaUpload;
use App\Services\Media\UploadUrlGenerator;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');

    // Presigning is an S3 concern; the lifecycle under test is ours.
    $this->swap(UploadUrlGenerator::class, new class implements UploadUrlGenerator
    {
        public function presign(string $disk, string $path, string $mime, DateTimeInterface $expiresAt): array
        {
            return ['url' => "https://bucket.test/{$path}?sig=stub", 'headers' => ['Content-Type' => $mime]];
        }
    });

    $this->actor = User::factory()->create();
});

function upload(string $filename, string $mime, int $size): array
{
    return app(RequestMediaUpload::class)->handle(test()->actor, $filename, $mime, $size);
}

/** Simulate the client's direct-to-bucket PUT. */
function putObject(Media $media, string $contents): void
{
    Storage::disk($media->disk)->put($media->path, $contents);
}

it('issues a presigned url and a pending media row', function () {
    ['media' => $media, 'upload_url' => $url] = upload('lecture.mp4', 'video/mp4', 1024);

    expect($media->status)->toBe(Media::STATUS_UPLOADING)
        ->and($media->kind)->toBe(Media::KIND_VIDEO)
        ->and($media->path)->toEndWith('.mp4')
        ->and($media->uploaded_by)->toBe($this->actor->id)
        ->and($url)->toContain($media->path);
});

it('derives kind from mime and rejects unsupported types', function () {
    expect(upload('a.png', 'image/png', 10)['media']->kind)->toBe(Media::KIND_IMAGE)
        ->and(upload('a.pdf', 'application/pdf', 10)['media']->kind)->toBe(Media::KIND_DOCUMENT)
        ->and(upload('a.mp3', 'audio/mpeg', 10)['media']->kind)->toBe(Media::KIND_AUDIO);

    expect(fn () => upload('evil.exe', 'application/x-msdownload', 10))
        ->toThrow(RuntimeException::class, 'Unsupported media type');
});

it('enforces a size ceiling per kind', function () {
    expect(fn () => upload('huge.png', 'image/png', 21 * 1024 * 1024))
        ->toThrow(RuntimeException::class, 'Image uploads are limited to 20 MB');
});

it('never trusts the filename extension', function () {
    $media = upload('lecture.mp4.exe;rm -rf', 'video/mp4', 10)['media'];

    expect($media->path)->toEndWith('.bin')
        ->and($media->path)->not->toContain('rm -rf');
});

it('puts a video into processing and submits it to the transcoder', function () {
    $bytes = 'fake-video-1';
    $media = upload('lecture.mp4', 'video/mp4', strlen($bytes))['media'];
    putObject($media, $bytes);

    $media = app(CompleteMediaUpload::class)->handle($media, hash('sha256', $bytes));

    expect($media->status)->toBe(Media::STATUS_PROCESSING)
        ->and($media->provider)->toBe('local')
        ->and($media->provider_asset_id)->toStartWith('local_')
        ->and($media->playback_id)->toBeNull();
});

it('marks a non-video ready immediately', function () {
    $bytes = 'fake-png';
    $media = upload('diagram.png', 'image/png', strlen($bytes))['media'];
    putObject($media, $bytes);

    $media = app(CompleteMediaUpload::class)->handle($media, hash('sha256', $bytes));

    expect($media->status)->toBe(Media::STATUS_READY)
        ->and($media->provider)->toBeNull();
});

it('fails an upload whose bytes do not match the declared size', function () {
    $media = upload('diagram.png', 'image/png', 9999)['media'];
    putObject($media, 'short');

    expect(fn () => app(CompleteMediaUpload::class)->handle($media, hash('sha256', 'short')))
        ->toThrow(RuntimeException::class, 'but 9999 were declared');

    expect($media->fresh()->status)->toBe(Media::STATUS_FAILED);
});

it('refuses to complete an upload that never arrived', function () {
    $media = upload('diagram.png', 'image/png', 42)['media'];

    expect(fn () => app(CompleteMediaUpload::class)->handle($media, hash('sha256', 'x')))
        ->toThrow(RuntimeException::class, 'No object was uploaded');
});

it('refuses a checksum that is not a sha-256 digest', function () {
    $media = upload('diagram.png', 'image/png', strlen('fake-png'))['media'];
    putObject($media, 'fake-png');

    expect(fn () => app(CompleteMediaUpload::class)->handle($media, 'not-a-digest'))
        ->toThrow(RuntimeException::class, 'hex-encoded SHA-256');
});

it('refuses to complete the same upload twice', function () {
    $media = upload('diagram.png', 'image/png', strlen('fake-png'))['media'];
    putObject($media, 'fake-png');

    $checksum = hash('sha256', 'fake-png');
    app(CompleteMediaUpload::class)->handle($media, $checksum);

    expect(fn () => app(CompleteMediaUpload::class)->handle($media->fresh(), $checksum))
        ->toThrow(RuntimeException::class, 'not awaiting an upload');
});

/**
 * Authors re-upload the same diagram constantly. Deduping repoints the new row
 * at the existing object and deletes the copy we just received.
 */
it('deduplicates an identical asset and drops the duplicate object', function () {
    $first = upload('diagram.png', 'image/png', strlen('fake-png'))['media'];
    putObject($first, 'fake-png');
    $checksum = hash('sha256', 'fake-png');
    app(CompleteMediaUpload::class)->handle($first, $checksum);

    $second = upload('diagram-copy.png', 'image/png', strlen('fake-png'))['media'];
    $duplicatePath = $second->path;
    putObject($second, 'fake-png');

    $second = app(CompleteMediaUpload::class)->handle($second, $checksum);

    expect($second->status)->toBe(Media::STATUS_READY)
        ->and($second->path)->toBe($first->fresh()->path)
        ->and($second->meta['deduplicated_from'])->toBe($first->id);

    Storage::disk('local')->assertMissing($duplicatePath);
    Storage::disk('local')->assertExists($first->fresh()->path);
});

it('does not deduplicate against an asset that is not ready yet', function () {
    $bytes = 'fake-video-1';
    $first = upload('a.mp4', 'video/mp4', strlen($bytes))['media'];
    putObject($first, $bytes);
    $checksum = hash('sha256', $bytes);
    app(CompleteMediaUpload::class)->handle($first, $checksum);   // -> processing

    $second = upload('b.mp4', 'video/mp4', strlen($bytes))['media'];
    putObject($second, $bytes);
    $second = app(CompleteMediaUpload::class)->handle($second, $checksum);

    // Reusing a still-transcoding asset would hand the author a playback_id
    // that does not exist yet.
    expect($second->status)->toBe(Media::STATUS_PROCESSING)
        ->and($second->meta)->not->toHaveKey('deduplicated_from')
        ->and($second->provider_asset_id)->not->toBe($first->fresh()->provider_asset_id);
});

it('never lets two rows claim the same provider asset', function () {
    Media::factory()->video()->create(['provider' => 'mux', 'provider_asset_id' => 'asset-1']);

    expectDatabaseRejection(
        fn () => Media::factory()->video()->create(['provider' => 'mux', 'provider_asset_id' => 'asset-1']),
        'media_provider_asset_unique',
    );
});

it('marks media ready from the transcoder webhook', function () {
    $media = Media::factory()->video()->processing()->create([
        'provider' => 'mux', 'provider_asset_id' => 'asset-9',
    ]);

    $media = app(MarkMediaReady::class)->ready('mux', 'asset-9', 'play-abc', 412);

    expect($media->status)->toBe(Media::STATUS_READY)
        ->and($media->playback_id)->toBe('play-abc')
        ->and($media->duration_s)->toBe(412);
});

it('ignores a replayed ready webhook', function () {
    $media = Media::factory()->video()->processing()->create([
        'provider' => 'mux', 'provider_asset_id' => 'asset-9',
    ]);

    app(MarkMediaReady::class)->ready('mux', 'asset-9', 'play-abc', 412);
    $again = app(MarkMediaReady::class)->ready('mux', 'asset-9', 'play-DIFFERENT', 999);

    expect($again->playback_id)->toBe('play-abc')
        ->and($again->duration_s)->toBe(412);
});

it('records a transcode failure with its reason', function () {
    Media::factory()->video()->processing()->create([
        'provider' => 'mux', 'provider_asset_id' => 'asset-9',
    ]);

    $media = app(MarkMediaReady::class)->failed('mux', 'asset-9', 'unsupported codec');

    expect($media->status)->toBe(Media::STATUS_FAILED)
        ->and($media->meta['failure_reason'])->toBe('unsupported codec');
});

it('rejects a webhook for an unknown asset', function () {
    expect(fn () => app(MarkMediaReady::class)->ready('mux', 'who-dis', 'p', 1))
        ->toThrow(RuntimeException::class, 'No media is registered');
});

it('refuses a ready row with no checksum, at the database level', function () {
    expectDatabaseRejection(
        fn () => Media::factory()->create(['status' => Media::STATUS_READY, 'checksum_sha256' => null]),
        'media_ready_has_checksum',
    );
});

<?php

namespace App\Http\Controllers\Studio;

use App\Authorization\Permissions;
use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Services\Media\CompleteMediaUpload;
use App\Services\Media\MediaPlayback;
use App\Services\Media\RequestMediaUpload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Direct media upload for the studio.
 *
 * Production streams big files straight to object storage with a presigned URL
 * (see RequestMediaUpload). This path proxies the bytes through the app onto the
 * public disk — fine for images and documents, which are small. Video is not
 * accepted here: a multi-gigabyte lecture has no business going through a PHP
 * worker, and it needs a transcode provider besides.
 */
class MediaController extends Controller
{
    /** Mime prefix → media kind. Video is deliberately absent. */
    private const KINDS = [
        'image/' => Media::KIND_IMAGE,
        'application/pdf' => Media::KIND_DOCUMENT,
    ];

    private const MAX_BYTES = [
        Media::KIND_IMAGE => 20 * 1024 * 1024,
        Media::KIND_DOCUMENT => 50 * 1024 * 1024,
    ];

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::MEDIA_UPLOAD), 403);

        // Validate by hand and return JSON: this is an XHR upload on a web route,
        // and the app's exception handler only auto-renders JSON for `api/*`, so a
        // thrown ValidationException here would try to redirect.
        $validator = Validator::make($request->all(), [
            'file' => ['required', 'file', 'max:51200', 'mimetypes:image/jpeg,image/png,image/gif,image/webp,image/svg+xml,application/pdf'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first('file'),
                'errors' => $validator->errors()->toArray(),
            ], 422);
        }

        $file = $request->file('file');
        $kind = $this->kindFor($file->getMimeType() ?? '');

        abort_if(
            $file->getSize() > self::MAX_BYTES[$kind],
            422,
            ucfirst($kind).' uploads are limited to '.(self::MAX_BYTES[$kind] / 1048576).' MB.',
        );

        // A UUID path — the original name is decoration, never trusted as a type.
        $path = sprintf('media/%s/%s.%s', $kind, Str::uuid7(), $file->getClientOriginalExtension() ?: 'bin');
        Storage::disk('public')->putFileAs(dirname($path), $file, basename($path));

        $media = Media::create([
            'disk' => 'public',
            'path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'checksum_sha256' => hash_file('sha256', $file->getRealPath()),
            'kind' => $kind,
            'status' => Media::STATUS_READY,
            'uploaded_by' => $request->user()->id,
        ]);

        return response()->json([
            'id' => $media->id,
            'kind' => $media->kind,
            'url' => $media->url(),
            'filename' => $media->original_filename,
            'size_bytes' => $media->size_bytes,
        ], 201);
    }

    private function kindFor(string $mime): string
    {
        foreach (self::KINDS as $prefix => $kind) {
            if (str_starts_with($mime, $prefix)) {
                return $kind;
            }
        }

        abort(422, "Unsupported media type [{$mime}].");
    }

    /*
    |--------------------------------------------------------------------------
    | Presigned (direct-to-bucket) upload — used for video, which is too big to
    | proxy through PHP and needs a transcode round-trip. Three steps: request a
    | target, upload to it, then complete (which verifies size and submits to the
    | transcoder). In dev the "bucket" is our own /blob proxy onto the local disk.
    |--------------------------------------------------------------------------
    */

    public function requestUpload(Request $request, RequestMediaUpload $uploader): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::MEDIA_UPLOAD), 403);

        $validator = Validator::make($request->all(), [
            'filename' => ['required', 'string', 'max:255'],
            'mime' => ['required', 'string', 'max:255'],
            'size_bytes' => ['required', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()->toArray()], 422);
        }

        try {
            $result = $uploader->handle(
                $request->user(),
                $request->string('filename'),
                $request->string('mime'),
                $request->integer('size_bytes'),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            ...$this->payload($result['media']),
            'upload_url' => $result['upload_url'],
            'headers' => $result['headers'],
        ], 201);
    }

    /**
     * Dev-only proxy receiver: the client PUTs the raw bytes here and we write
     * them to the local disk. Production PUTs straight to the bucket instead.
     */
    public function blob(Request $request): Response
    {
        abort_unless($request->user()->can(Permissions::MEDIA_UPLOAD), 403);

        $media = Media::query()
            ->where('path', (string) $request->query('path'))
            ->where('status', Media::STATUS_UPLOADING)
            ->firstOrFail();

        Storage::disk($media->disk)->put($media->path, $request->getContent());

        return response()->noContent();
    }

    public function complete(Request $request, Media $media, CompleteMediaUpload $completer): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::MEDIA_UPLOAD), 403);

        $validator = Validator::make($request->all(), [
            'checksum' => ['required', 'string', 'size:64'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()->toArray()], 422);
        }

        try {
            $media = $completer->handle($media, $request->string('checksum'));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->payload($media));
    }

    /** Polled by the studio while a video transcodes. */
    public function show(Request $request, Media $media): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::MEDIA_UPLOAD), 403);

        return response()->json($this->payload($media));
    }

    /** @return array<string, mixed> */
    private function payload(Media $media): array
    {
        return [
            'id' => $media->id,
            'kind' => $media->kind,
            'status' => $media->status,
            'url' => $media->url(),
            'playback' => app(MediaPlayback::class)->video($media),
            'filename' => $media->original_filename,
            'size_bytes' => $media->size_bytes,
            'duration_s' => $media->duration_s,
        ];
    }
}

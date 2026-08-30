<?php

use App\Http\Controllers\Api\ActivityController;
use App\Http\Controllers\Api\AssessmentController;
use App\Http\Controllers\Api\AttemptController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\ClientConsoleController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\LaunchController;
use App\Http\Controllers\Api\MediaFileController;
use App\Http\Controllers\Api\MediaStreamController;
use App\Http\Controllers\Api\MediaWebhookController;
use App\Http\Controllers\Api\MyCoursesController;
use App\Http\Controllers\Api\ProgressController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\QuestionStatsController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\TutorController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Controllers\Portal\CatalogController as PortalCatalogController;
use App\Http\Controllers\Portal\CourseController as PortalCourseController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// ─── Public learning portal (no auth) ───────────────────────────────────
// Published courses anyone can browse and learn without signing in. Which
// courses are public is decided by App\Portal\CourseGate (Phase 1: any
// published course). Snapshots are ETag'd, so these are cache-friendly.
Route::middleware('throttle:public')->prefix('v1/portal')->group(function () {
    Route::get('/courses', [PortalCatalogController::class, 'index']);
    Route::get('/categories', [PortalCatalogController::class, 'categories']);
    Route::get('/courses/{course:slug}', [PortalCourseController::class, 'show']);
    Route::get('/courses/{course:slug}/content', [PortalCourseController::class, 'content']);
    // Public streaming of self-hosted course video (Mux plays from its own CDN).
    Route::get('/media/{media}/stream', [\App\Http\Controllers\Portal\MediaController::class, 'stream'])
        ->name('portal.media.stream');
});

Route::middleware('auth:sanctum')->prefix('v1')->group(function () {
    Route::get('/me/dashboard', [DashboardController::class, 'index']);
    Route::get('/me/courses', [MyCoursesController::class, 'index']);
    Route::get('/me/courses/{course}/content', [MyCoursesController::class, 'content']);
    Route::get('/me/courses/{course}/progress', [ProgressController::class, 'show']);
    Route::post('/me/progress', [ProgressController::class, 'store']);

    // Assessments: discover them on a course, then run the attempt lifecycle.
    Route::get('/me/courses/{course}/assessments', [AssessmentController::class, 'index']);
    Route::post('/me/assessments/{assessment}/attempts', [AttemptController::class, 'store']);
    Route::get('/me/attempts/{attempt}', [AttemptController::class, 'show']);
    Route::post('/me/attempts/{attempt}/answers', [AttemptController::class, 'answer']);
    Route::post('/me/attempts/{attempt}/submit', [AttemptController::class, 'submit']);
    // B2C storefront: browse what's for sale, then open a checkout.
    Route::get('/me/catalog', [CatalogController::class, 'index']);
    Route::get('/me/subscriptions', [SubscriptionController::class, 'index']);
    Route::post('/me/subscriptions', [SubscriptionController::class, 'store'])->middleware('throttle:checkout');
    Route::post('/me/purchases', [PurchaseController::class, 'store'])->middleware('throttle:checkout');
    Route::post('/activity', [ActivityController::class, 'store'])->middleware('throttle:activity');
    Route::get('/questions/{question}/stats', [QuestionStatsController::class, 'show']);
    Route::get('/search', SearchController::class)->middleware('throttle:search');

    // AI tutor: conversations are course-scoped and private to the learner.
    Route::get('/me/tutor/usage', [TutorController::class, 'usage']);
    Route::get('/me/courses/{course}/tutor/conversations', [TutorController::class, 'index']);
    Route::post('/me/courses/{course}/tutor/conversations', [TutorController::class, 'start']);
    Route::get('/me/tutor/conversations/{conversation}', [TutorController::class, 'show']);
    Route::post('/me/tutor/conversations/{conversation}/messages', [TutorController::class, 'message'])
        ->middleware('throttle:tutor');
    Route::post('/me/tutor/conversations/{conversation}/stream', [TutorController::class, 'stream'])
        ->middleware('throttle:tutor');
});

// Streams a locally-transcoded video (dev provider only; prod is served by the
// transcode CDN). Range-capable so the learner can seek. Accepts both guards:
// the app carries a bearer token, the studio "preview as learner" a session
// cookie — the same baked URL must play in both.
Route::get('/v1/media/{media}/stream', [MediaStreamController::class, 'show'])
    ->middleware('auth:sanctum,web')
    ->name('api.media.stream');

// Serves image/document/audio bytes from a local disk, under the CORS policy the
// static `/storage` symlink cannot offer. Public, like `/storage` was.
Route::get('/v1/media/{media}/file', [MediaFileController::class, 'show'])->name('api.media.file');

// No auth middleware: the provider authenticates with an HMAC over the raw body.
Route::post('/v1/webhooks/razorpay', [WebhookController::class, 'razorpay']);
Route::post('/v1/webhooks/mux', [MediaWebhookController::class, 'mux']);

// Unauthenticated: the client authenticates with a signed launch token.
Route::post('/v1/launch', [LaunchController::class, 'launch'])->middleware('throttle:launch');
Route::post('/v1/auth/launch/exchange', [LaunchController::class, 'exchange'])->middleware('throttle:launch');

// Partner (B2B client) read-only catalogue — client signs a JWT with its launch key.
Route::middleware('client')->prefix('v1/partner')->group(function () {
    Route::get('/courses', [\App\Http\Controllers\Api\PartnerCatalogController::class, 'courses']);
    Route::get('/courses/{code}/content', [\App\Http\Controllers\Api\PartnerCatalogController::class, 'content']);
});

// B2C direct sign-up and login for the learner app.
Route::post('/v1/auth/register', [AuthController::class, 'register'])->middleware('throttle:register');
Route::post('/v1/auth/token', [AuthController::class, 'token'])->middleware('throttle:login');
Route::post('/v1/auth/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');

/*
 * The client console. Every route is client-scoped by middleware, and
 * ClientScopeCoverageTest fails if one ever is not.
 */
Route::middleware(['auth:sanctum', 'client.scope'])
    ->prefix('v1/client')
    ->name('client.')
    ->group(function () {
        Route::get('/roster', [ClientConsoleController::class, 'roster'])->name('roster');
        Route::get('/seats', [ClientConsoleController::class, 'seats'])->name('seats');
        Route::get('/activity', [ClientConsoleController::class, 'activity'])->name('activity');
        Route::get('/delivery', [ClientConsoleController::class, 'delivery'])->name('delivery');
    });

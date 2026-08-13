<?php

use App\Http\Controllers\Ops\ClientController;
use App\Http\Controllers\Ops\ClientKeyController;
use App\Http\Controllers\Ops\EntitlementController;
use App\Http\Controllers\Ops\ProductController;
use App\Http\Controllers\Studio\AssessmentController;
use App\Http\Controllers\Studio\AssessmentQuestionController;
use App\Http\Controllers\Studio\ContentBlockController;
use App\Http\Controllers\Studio\LessonBuilderController;
use App\Http\Controllers\Studio\CourseController;
use App\Http\Controllers\Studio\CourseGrantController;
use App\Http\Controllers\Studio\CourseInsightsController;
use App\Http\Controllers\Studio\CourseNodeController;
use App\Http\Controllers\Studio\CourseWorkflowController;
use App\Http\Controllers\Studio\DashboardController;
use App\Http\Controllers\Studio\GenerationController;
use App\Http\Controllers\Studio\LearnerController;
use App\Http\Controllers\Studio\MediaController;
use App\Http\Controllers\Studio\QuestionBankController;
use App\Http\Controllers\Studio\QuestionController;
use App\Http\Controllers\Studio\SchemaController;
use App\Http\Controllers\Studio\SchemaLevelController;
use App\Http\Controllers\Studio\SchemaVersionController;
use App\Http\Controllers\Studio\SessionController;
use App\Http\Controllers\Studio\SetPasswordController;
use App\Http\Controllers\Studio\StaffController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/studio');

/*
 * The studio: session-cookie auth, staff only.
 *
 * `staff` is the second lock. Client-provisioned users already hold no password
 * (users_provisioned_has_no_password), and a learner or a client_admin holding a
 * session must still never reach an authoring surface. See docs/13 §2.
 */
Route::prefix('studio')->name('studio.')->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('/login', [SessionController::class, 'create'])->name('login');
        Route::post('/login', [SessionController::class, 'store'])->middleware('throttle:login');

        // Where a staff invitation lands: set a password, then sign in.
        Route::get('/set-password/{token}', [SetPasswordController::class, 'show'])->name('password.set');
        Route::post('/set-password', [SetPasswordController::class, 'store'])
            ->middleware('throttle:login')->name('password.store');
    });

    Route::middleware(['auth', 'staff'])->group(function () {
        Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');
        Route::get('/', DashboardController::class)->name('dashboard');

        // Staff management (admin-only via user.manage inside the controller).
        Route::get('/users', [StaffController::class, 'index'])->name('users.index');
        Route::post('/users', [StaffController::class, 'store'])->name('users.store');
        Route::patch('/users/{user}', [StaffController::class, 'update'])->name('users.update');
        Route::post('/users/{user}/invite', [StaffController::class, 'invite'])->name('users.invite');

        // B2C learner administration (admin-only via learner.manage).
        Route::get('/learners', [LearnerController::class, 'index'])->name('learners.index');
        Route::get('/learners/{learner}', [LearnerController::class, 'show'])->name('learners.show');
        Route::patch('/learners/{learner}/status', [LearnerController::class, 'updateStatus'])->name('learners.status');
        Route::post('/learners/{learner}/comps', [LearnerController::class, 'grant'])->name('learners.comps.store');
        Route::delete('/comps/{comp}', [LearnerController::class, 'revokeGrant'])->name('comps.destroy');

        Route::get('/schemas', [SchemaController::class, 'index'])->name('schemas.index');
        Route::post('/schemas', [SchemaController::class, 'store'])->name('schemas.store');
        Route::delete('/schemas/{schema}', [SchemaController::class, 'destroy'])->name('schemas.destroy');

        Route::get('/schema-versions/{version}', [SchemaVersionController::class, 'show'])
            ->name('schema-versions.show');
        Route::post('/schema-versions/{version}/publish', [SchemaVersionController::class, 'publish'])
            ->name('schema-versions.publish');
        Route::post('/schema-versions/{version}/clone', [SchemaVersionController::class, 'clone'])
            ->name('schema-versions.clone');

        // AI course generation (from a PDF or a topic brief).
        Route::get('/generate', [GenerationController::class, 'index'])->name('generate.index');
        Route::post('/generate', [GenerationController::class, 'store'])->name('generate.store');
        Route::get('/generate/settings', [GenerationController::class, 'settings'])->name('generate.settings');
        Route::post('/generate/settings', [GenerationController::class, 'updateSettings'])->name('generate.settings.update');
        Route::post('/generate/{generation}/retry', [GenerationController::class, 'retry'])->name('generate.retry');

        Route::get('/courses', [CourseController::class, 'index'])->name('courses.index');
        Route::post('/courses', [CourseController::class, 'store'])->name('courses.store');
        Route::get('/courses/{course}', [CourseController::class, 'show'])->name('courses.show');
        Route::get('/courses/{course}/preview', [CourseController::class, 'preview'])
            ->name('courses.preview');
        Route::get('/courses/{course}/published', [CourseController::class, 'published'])
            ->name('courses.published');
        Route::get('/courses/{course}/insights', [CourseInsightsController::class, 'show'])
            ->name('courses.insights');

        Route::get('/courses/{course}/team', [CourseGrantController::class, 'index'])->name('courses.team');
        Route::post('/courses/{course}/grants', [CourseGrantController::class, 'store'])->name('course-grants.store');
        Route::delete('/course-grants/{courseGrant}', [CourseGrantController::class, 'destroy'])
            ->name('course-grants.destroy');

        Route::get('/courses/{course}/review', [CourseWorkflowController::class, 'show'])
            ->name('courses.review');
        Route::post('/courses/{course}/submit', [CourseWorkflowController::class, 'submit'])
            ->name('courses.submit');
        Route::post('/courses/{course}/withdraw', [CourseWorkflowController::class, 'withdraw'])
            ->name('courses.withdraw');
        Route::post('/courses/{course}/approve', [CourseWorkflowController::class, 'approve'])
            ->name('courses.approve');
        Route::post('/courses/{course}/request-changes', [CourseWorkflowController::class, 'requestChanges'])
            ->name('courses.request-changes');
        Route::post('/courses/{course}/publish', [CourseWorkflowController::class, 'publish'])
            ->name('courses.publish');
        Route::post('/courses/{course}/revise', [CourseWorkflowController::class, 'revise'])
            ->name('courses.revise');

        // Question banks and their questions.
        Route::get('/questions', [QuestionBankController::class, 'index'])->name('question-banks.index');
        Route::post('/questions', [QuestionBankController::class, 'store'])->name('question-banks.store');
        Route::get('/question-banks/{bank}', [QuestionBankController::class, 'show'])
            ->name('question-banks.show');
        Route::post('/question-banks/{bank}/questions', [QuestionController::class, 'store'])
            ->name('questions.store');
        Route::patch('/questions/{question}', [QuestionController::class, 'update'])
            ->name('questions.update');
        Route::delete('/questions/{question}', [QuestionController::class, 'destroy'])
            ->name('questions.destroy');

        Route::post('/courses/{course}/nodes', [CourseNodeController::class, 'store'])
            ->name('course-nodes.store');
        Route::patch('/course-nodes/{node}', [CourseNodeController::class, 'update'])
            ->name('course-nodes.update');
        Route::post('/course-nodes/{node}/move', [CourseNodeController::class, 'move'])
            ->name('course-nodes.move');
        Route::delete('/course-nodes/{node}', [CourseNodeController::class, 'destroy'])
            ->name('course-nodes.destroy');

        Route::post('/media', [MediaController::class, 'store'])->name('media.store');

        // Presigned (direct-to-bucket) upload flow, used for video: request a
        // target, upload to it (the /blob proxy stands in for the bucket in dev),
        // then complete. Poll show() while the transcode runs.
        Route::post('/media/uploads', [MediaController::class, 'requestUpload'])->name('media.request');
        Route::match(['put', 'post'], '/media/blob', [MediaController::class, 'blob'])->name('media.blob');
        Route::post('/media/uploads/{media}/complete', [MediaController::class, 'complete'])->name('media.complete');
        Route::get('/media/{media}', [MediaController::class, 'show'])->name('media.show');

        Route::get('/course-nodes/{node}/content', [ContentBlockController::class, 'index'])
            ->name('content-blocks.index');
        Route::post('/course-nodes/{node}/content', [ContentBlockController::class, 'store'])
            ->name('content-blocks.store');
        Route::post('/course-nodes/{node}/media-blocks', [ContentBlockController::class, 'storeMedia'])
            ->name('content-blocks.store-media');
        Route::patch('/content-blocks/{block}', [ContentBlockController::class, 'update'])
            ->name('content-blocks.update');
        Route::post('/content-blocks/{block}/move', [ContentBlockController::class, 'move'])
            ->name('content-blocks.move');
        Route::delete('/content-blocks/{block}', [ContentBlockController::class, 'destroy'])
            ->name('content-blocks.destroy');

        // Interactive animated-lesson builder (docs/14 WS3) — JSON, polled by React.
        Route::get('/course-nodes/{lesson}/lesson-preview', [LessonBuilderController::class, 'preview'])->name('lesson-builder.preview');
        Route::post('/course-nodes/{lesson}/lesson-builder/next-step', [LessonBuilderController::class, 'nextStep'])->name('lesson-builder.next');
        Route::post('/course-nodes/{lesson}/lesson-builder/revise-step', [LessonBuilderController::class, 'reviseStep'])->name('lesson-builder.revise');
        Route::get('/course-nodes/{lesson}/lesson-builder/step-status', [LessonBuilderController::class, 'stepStatus'])->name('lesson-builder.status');
        Route::post('/course-nodes/{lesson}/lesson-builder/commit', [LessonBuilderController::class, 'commit'])->name('lesson-builder.commit');

        // Assessments (quizzes and tests) on a node, and their questions.
        Route::get('/course-nodes/{node}/assessments', [AssessmentController::class, 'index'])
            ->name('assessments.index');
        Route::post('/course-nodes/{node}/assessments', [AssessmentController::class, 'store'])
            ->name('assessments.store');
        Route::get('/assessments/{assessment}', [AssessmentController::class, 'show'])
            ->name('assessments.show');
        Route::patch('/assessments/{assessment}', [AssessmentController::class, 'update'])
            ->name('assessments.update');
        Route::delete('/assessments/{assessment}', [AssessmentController::class, 'destroy'])
            ->name('assessments.destroy');
        Route::post('/assessments/{assessment}/questions', [AssessmentQuestionController::class, 'store'])
            ->name('assessment-questions.store');
        Route::patch('/assessment-questions/{assessmentQuestion}', [AssessmentQuestionController::class, 'update'])
            ->name('assessment-questions.update');
        Route::post('/assessment-questions/{assessmentQuestion}/move', [AssessmentQuestionController::class, 'move'])
            ->name('assessment-questions.move');
        Route::delete('/assessment-questions/{assessmentQuestion}', [AssessmentQuestionController::class, 'destroy'])
            ->name('assessment-questions.destroy');

        Route::post('/schema-versions/{version}/levels', [SchemaLevelController::class, 'store'])
            ->name('schema-levels.store');
        Route::patch('/schema-levels/{level}', [SchemaLevelController::class, 'update'])
            ->name('schema-levels.update');
        Route::delete('/schema-levels/{level}', [SchemaLevelController::class, 'destroy'])
            ->name('schema-levels.destroy');
    });
});

/*
 * Ops: the B2B operations surface (clients, products, entitlements). Same staff
 * auth as the studio; the nav shows it only to holders of the ops permissions.
 */
Route::prefix('ops')->name('ops.')->middleware(['auth', 'staff'])->group(function () {
    Route::get('/clients', [ClientController::class, 'index'])->name('clients.index');
    Route::post('/clients', [ClientController::class, 'store'])->name('clients.store');
    Route::get('/clients/{client}', [ClientController::class, 'show'])->name('clients.show');
    Route::patch('/clients/{client}', [ClientController::class, 'update'])->name('clients.update');
    Route::post('/clients/{client}/keys', [ClientKeyController::class, 'store'])->name('client-keys.store');
    Route::delete('/client-keys/{clientKey}', [ClientKeyController::class, 'revoke'])->name('client-keys.revoke');
    Route::patch('/clients/{client}/webhook', [ClientController::class, 'updateWebhook'])->name('clients.webhook');
    Route::post('/clients/{client}/webhook/secret', [ClientController::class, 'rotateSecret'])
        ->name('clients.webhook.secret');
    Route::patch('/clients/{client}/ai-tutor', [ClientController::class, 'updateAiTutor'])->name('clients.ai-tutor');

    Route::post('/clients/{client}/entitlements', [EntitlementController::class, 'store'])
        ->name('entitlements.store');
    Route::patch('/entitlements/{entitlement}', [EntitlementController::class, 'update'])
        ->name('entitlements.update');
    Route::delete('/entitlements/{entitlement}', [EntitlementController::class, 'destroy'])
        ->name('entitlements.destroy');

    Route::get('/products', [ProductController::class, 'index'])->name('products.index');
    Route::post('/products', [ProductController::class, 'store'])->name('products.store');
    Route::get('/products/{product}', [ProductController::class, 'show'])->name('products.show');
    Route::patch('/products/{product}', [ProductController::class, 'update'])->name('products.update');
    Route::post('/products/{product}/courses', [ProductController::class, 'addCourse'])
        ->name('products.courses.add');
    Route::delete('/products/{product}/courses/{course}', [ProductController::class, 'removeCourse'])
        ->name('products.courses.remove');
});

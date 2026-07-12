<?php

namespace Database\Seeders;

use App\Assessments\QuestionType;
use App\Authorization\Roles;
use App\ContentBlocks\BlockType;
use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\CompGrant;
use App\Models\Course;
use App\Models\CourseNode;
use App\Models\CourseSchema;
use App\Models\Media;
use App\Models\NodeProgress;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Question;
use App\Models\QuestionBank;
use App\Models\SchemaLevel;
use App\Models\SchemaVersion;
use App\Models\User;
use App\Services\Assessments\AssessmentEditor;
use App\Services\Catalog\ManageProduct;
use App\Services\Content\BlockEditor;
use App\Services\Publishing\PublishCourse;
use App\Services\Schemas\PublishSchemaVersion;
use App\Services\Tree\CourseTree;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A ready-to-take demo for the learner app: a published course with a Unit and a
 * Lesson (rich text), a quiz on the Lesson, and a B2C learner entitled to it.
 *
 * Local only, and idempotent — safe to re-run. `php artisan db:seed --class=DemoContentSeeder`.
 */
class DemoContentSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }

        $admin = User::where('email', 'admin@example.com')->first();

        $learner = User::firstOrCreate(
            ['email' => 'learner@example.com'],
            ['name' => 'Test Learner', 'password' => 'password', 'status' => 'active', 'kind' => User::KIND_LOCAL],
        );
        if (! $learner->hasRole(Roles::LEARNER)) {
            $learner->assignRole(Roles::LEARNER);
        }

        // Rebuild from scratch each run so schema tweaks (e.g. new block types)
        // take effect. Dev-only data, so a clean slate is fine — but the demo
        // course is referenced by attempts, progress and the product, which must
        // be cleared before it can be hard-deleted.
        foreach (Course::where('code', 'DEMO-101')->withTrashed()->get() as $old) {
            $pubIds = $old->publications()->pluck('id');
            AssessmentAttempt::whereIn('publication_id', $pubIds)->delete();
            NodeProgress::whereIn('publication_id', $pubIds)->delete();
            DB::table('product_courses')->where('course_id', $old->id)->delete();
            // A B2B launch (see `launch:demo`) links the course to a client;
            // clear those before the course can be hard-deleted.
            DB::table('resource_links')->where('course_id', $old->id)->delete();
            $old->forceDelete();
        }
        // A published schema version cannot be hard-deleted (immutability). Free
        // the slug for the fresh build and soft-delete the old schema so retired
        // demo schemas do not pile up in the studio's schema list each run.
        foreach (CourseSchema::withTrashed()->where('slug', 'demo-schema')->get() as $retired) {
            $retired->update(['slug' => 'demo-schema-retired-'.Str::lower(Str::random(8))]);
            $retired->delete();
        }

        $course = DB::transaction(fn () => $this->buildCourse($admin));

        // Sell it to the learner (comp grant) so it appears in /me/courses.
        $product = Product::firstOrCreate(['sku' => 'DEMO-101'], [
            'name' => $course->title, 'kind' => 'course', 'status' => 'active',
        ]);
        app(ManageProduct::class)->addCourse($product, $course, $admin);
        CompGrant::firstOrCreate(
            ['user_id' => $learner->id, 'product_id' => $product->id],
            ['reason' => CompGrant::REASON_TRIAL, 'starts_at' => now()->subMinute()],
        );

        // A plan on the owned product (shows as "Owned" in the store) and a
        // separate, ungranted product so the storefront shows a buyable card.
        // Live checkout needs Razorpay keys; the listing works without them.
        Plan::firstOrCreate(['code' => 'DEMO-MONTHLY'], [
            'product_id' => $product->id, 'name' => 'Monthly', 'price_minor' => 49900,
            'currency' => 'INR', 'interval' => Plan::MONTHLY, 'trial_days' => 7,
            'provider_ref' => 'plan_demo_monthly', 'status' => 'active',
        ]);

        $pro = Product::firstOrCreate(['sku' => 'DEMO-PRO'], [
            'name' => 'Demo Pro (all access)', 'kind' => 'bundle', 'status' => 'active',
        ]);
        app(ManageProduct::class)->addCourse($pro, $course, $admin);
        Plan::firstOrCreate(['code' => 'DEMO-PRO-YEARLY'], [
            'product_id' => $pro->id, 'name' => 'Yearly', 'price_minor' => 499900,
            'currency' => 'INR', 'interval' => Plan::YEARLY, 'trial_days' => 0,
            'provider_ref' => 'plan_demo_pro', 'status' => 'active',
        ]);

        $this->command->info("Demo course \"{$course->title}\" ready. Sign in to the app as learner@example.com / password.");
    }

    private function buildCourse(?User $admin): Course
    {
        // A tiny schema whose Lesson level carries content AND allows assessments.
        $schema = CourseSchema::create(['name' => 'Demo Schema', 'slug' => 'demo-schema', 'created_by' => $admin?->id]);
        $version = SchemaVersion::create([
            'course_schema_id' => $schema->id, 'version' => 1, 'status' => SchemaVersion::STATUS_DRAFT,
        ]);

        $unit = SchemaLevel::create([
            'schema_version_id' => $version->id, 'name' => 'Unit', 'plural_name' => 'Units',
            'depth' => 0, 'sort_key' => 'V', 'min_occurrences' => 1, 'numbering_style' => 'numeric',
            'label_template' => 'Unit {n}', 'allows_content' => false, 'allowed_block_types' => [],
            'allows_assessment' => false,
        ]);
        SchemaLevel::create([
            'schema_version_id' => $version->id, 'parent_level_id' => $unit->id, 'name' => 'Lesson',
            'plural_name' => 'Lessons', 'depth' => 1, 'sort_key' => 'V', 'min_occurrences' => 1,
            'numbering_style' => 'numeric', 'label_template' => 'Lesson {n}: {title}',
            'allows_content' => true, 'allowed_block_types' => ['rich_text', 'callout', 'image', 'attachment'],
            'allows_assessment' => true,
        ]);

        app(PublishSchemaVersion::class)->handle($version, $admin ?? User::factory()->create());
        $version->refresh();

        $unitLevel = $version->levels()->where('name', 'Unit')->firstOrFail();
        $lessonLevel = $version->levels()->where('name', 'Lesson')->firstOrFail();

        $course = Course::create([
            'title' => 'Demo Course', 'code' => 'DEMO-101', 'subject' => 'Demo',
            'language' => 'en', 'schema_version_id' => $version->id,
            'workflow_state' => Course::STATE_DRAFT, 'created_by' => $admin?->id,
        ]);

        $tree = app(CourseTree::class);
        $unitNode = $tree->appendNode($course, $unitLevel, 'Getting Started');
        $lesson = $tree->appendNode($course, $lessonLevel, 'Welcome', $unitNode);

        app(BlockEditor::class)->create($lesson, BlockType::RichText->value)->update([
            'payload' => ['format' => 'portable_text', 'body' => [[
                '_type' => 'block', 'style' => 'normal', 'markDefs' => [],
                'children' => [['_type' => 'span', 'text' => 'Welcome to the demo course. Read this, then take the quiz.', 'marks' => []]],
            ]]],
        ]);

        $this->addImage($lesson, $admin);

        app(PublishCourse::class)->handle($course->fresh(), $admin ?? User::factory()->create());

        $this->addQuiz($course->fresh(), $lesson->fresh(), $admin);

        return $course->fresh();
    }

    /** Generate a small PNG, store it, and attach it as an image block. */
    private function addImage(CourseNode $lesson, ?User $admin): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            return; // GD not available — skip; the rest of the demo still works.
        }

        $img = imagecreatetruecolor(640, 320);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, 79, 70, 229));
        imagestring($img, 5, 250, 150, 'Demo image', (int) imagecolorallocate($img, 255, 255, 255));

        ob_start();
        imagepng($img);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        $path = 'media/image/'.Str::uuid7().'.png';
        Storage::disk('public')->put($path, $bytes);

        $media = Media::create([
            'disk' => 'public', 'path' => $path, 'original_filename' => 'demo-image.png',
            'mime' => 'image/png', 'size_bytes' => strlen($bytes),
            'checksum_sha256' => hash('sha256', $bytes), 'kind' => Media::KIND_IMAGE,
            'status' => Media::STATUS_READY, 'uploaded_by' => $admin?->id,
        ]);

        app(BlockEditor::class)->appendMedia($lesson, 'image', $media->id, ['alt' => 'A demo image']);
    }

    private function addQuiz(Course $course, CourseNode $lesson, ?User $admin): void
    {
        $bank = QuestionBank::firstOrCreate(['name' => 'Demo Bank', 'course_id' => null]);
        $editor = app(AssessmentEditor::class);
        $quiz = $editor->create($lesson, Assessment::KIND_QUIZ, 'Welcome Quiz');

        $q1 = Question::factory()->ofType(QuestionType::McqSingle)
            ->withOptions([['text' => 'Paris', 'correct' => true], ['text' => 'London'], ['text' => 'Rome']])
            ->create(['question_bank_id' => $bank->id, 'default_points' => 10, 'stem' => $this->stem('What is the capital of France?')]);
        $q2 = Question::factory()->ofType(QuestionType::TrueFalse, ['answer' => true])
            ->create(['question_bank_id' => $bank->id, 'default_points' => 10, 'stem' => $this->stem('The Earth orbits the Sun.')]);

        $editor->addQuestion($quiz, $q1);
        $editor->addQuestion($quiz, $q2);
    }

    /** @return array<string, mixed> */
    private function stem(string $text): array
    {
        return ['format' => 'portable_text', 'body' => [[
            '_type' => 'block', 'style' => 'normal', 'markDefs' => [],
            'children' => [['_type' => 'span', 'text' => $text, 'marks' => []]],
        ]]];
    }
}

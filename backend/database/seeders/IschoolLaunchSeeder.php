<?php

namespace Database\Seeders;

use App\ContentBlocks\BlockType;
use App\Models\Client;
use App\Models\ClientEntitlement;
use App\Models\ClientKey;
use App\Models\Course;
use App\Models\CourseSchema;
use App\Models\Product;
use App\Models\SchemaVersion;
use App\Models\User;
use App\Services\Catalog\ManageProduct;
use App\Services\Content\BlockEditor;
use App\Services\Courses\CreateCourse;
use App\Services\Publishing\PublishCourse;
use App\Services\Tree\CourseTree;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Registers ischool as a custom-JWT launch Client and gives it something to
 * launch into (docs/14 WS4, docs/10 §5): an RS256 key (ischool holds the private
 * half), a published animated-lesson course with a stable code, a Product, and a
 * ClientEntitlement.
 *
 * The private key is written to storage/app/launch/ischool-private.pem for the
 * ischool side / the verify command. In production ischool generates the pair and
 * only the public half is registered here.
 *
 * Idempotent.
 */
class IschoolLaunchSeeder extends Seeder
{
    private const KID = 'ischool-2026';

    private const COURSE_CODE = 'ANIM-DEMO-01';

    public function run(): void
    {
        $admin = User::query()->orderBy('id')->first() ?? User::factory()->create();

        $client = Client::firstOrCreate(
            ['slug' => 'ischool'],
            ['name' => 'iSchool (Samchita)', 'status' => Client::ACTIVE, 'integration' => Client::CUSTOM_JWT],
        );

        $this->ensureKey($client);
        $course = $this->ensureCourse($admin);
        $this->ensureEntitlement($client, $course, $admin);

        $this->command?->info('ischool launch client ready.');
        $this->command?->line('  client slug : ischool');
        $this->command?->line('  key kid     : '.self::KID);
        $this->command?->line('  private key : '.storage_path('app/launch/ischool-private.pem'));
        $this->command?->line('  course code : '.self::COURSE_CODE.'  ('.$course->id.')');
    }

    private function ensureKey(Client $client): void
    {
        if ($client->keys()->where('kid', self::KID)->exists()) {
            return;
        }

        $res = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($res, $privatePem);
        $publicPem = openssl_pkey_get_details($res)['key'];

        // LMS keeps the public half; ischool signs with the private half.
        ClientKey::create([
            'client_id' => $client->id,
            'kid' => self::KID,
            'algorithm' => 'RS256',
            'public_key' => $publicPem,
            'status' => 'active',
        ]);

        Storage::disk('local')->put('launch/ischool-private.pem', $privatePem);
    }

    private function ensureCourse(User $admin): Course
    {
        if ($existing = Course::where('code', self::COURSE_CODE)->first()) {
            return $existing;
        }

        $schema = CourseSchema::where('slug', 'animated-lesson')->firstOrFail();
        $version = SchemaVersion::where('course_schema_id', $schema->id)
            ->where('status', SchemaVersion::STATUS_PUBLISHED)
            ->orderByDesc('version')->firstOrFail();

        $chapterLevel = $version->levels()->where('name', 'Chapter')->firstOrFail();
        $lessonLevel = $version->levels()->where('name', 'Lesson')->firstOrFail();
        $stepLevel = $version->levels()->where('name', 'Step')->firstOrFail();

        $course = app(CreateCourse::class)->handle(
            ['title' => 'Science — Sample Animated Lesson', 'code' => self::COURSE_CODE, 'subject' => 'Science', 'language' => 'en'],
            $version,
            $admin,
        );

        $tree = app(CourseTree::class);
        $chapter = $tree->appendNode($course, $chapterLevel, 'Weather & Water');
        $lesson = $tree->appendNode($course, $lessonLevel, 'The Water Cycle', $chapter);
        $step = $tree->appendNode($course, $stepLevel, 'What Is the Water Cycle?', $lesson);

        app(BlockEditor::class)->appendAuthored($step, BlockType::AnimatedReveal->value, [
            'voice_script' => 'The water cycle moves water around our planet in a never-ending loop.',
            'fragments' => [
                ['md' => '# The Water Cycle', 'effect' => 'zoom', 'voice' => 'This is the water cycle.', 'duration_ms' => 500],
                ['md' => 'Water is always **moving** — from the sea, to the sky, to the land.', 'effect' => 'fade', 'voice' => 'Water is always moving from the sea to the sky to the land.', 'duration_ms' => 500],
                ['md' => '- It **evaporates**, forms **clouds**, then **rains** back down.', 'effect' => 'slide-up', 'voice' => 'It evaporates, forms clouds, then rains back down.', 'duration_ms' => 500],
            ],
        ]);

        app(PublishCourse::class)->handle($course->fresh(), $admin);

        return $course->fresh();
    }

    private function ensureEntitlement(Client $client, Course $course, User $admin): void
    {
        $product = Product::firstOrCreate(
            ['sku' => 'ISCHOOL-SUBJECTS'],
            ['name' => 'iSchool Subjects (catalogue)', 'kind' => 'bundle', 'status' => 'active'],
        );

        app(ManageProduct::class)->addCourse($product, $course, $admin);

        ClientEntitlement::firstOrCreate(
            ['client_id' => $client->id, 'product_id' => $product->id],
            [
                'seat_model' => ClientEntitlement::UNLIMITED,
                'seat_limit' => null,
                'starts_at' => now(),
                'ends_at' => now()->addYear(),
                'status' => 'active',
                'contract_ref' => 'WS4-demo',
            ],
        );
    }
}

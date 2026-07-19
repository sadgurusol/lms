<?php

namespace App\Jobs;

use App\Models\CourseGeneration;
use App\Models\User;
use App\Services\Generation\BlueprintGenerator;
use App\Services\Generation\CourseBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs a course generation off the request: ask the model for an outline, build
 * the draft course from it, and record the result. AI generation is slow and
 * costly, so this never auto-retries. See docs/14-course-generation.md.
 */
class GenerateCourseJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public readonly string $generationId)
    {
        // Two-phase generation is one API call per topic, so a real course can
        // run for many minutes. Configurable; keep below the queue retry_after.
        $this->timeout = (int) config('services.anthropic.generation_timeout', 1800);
    }

    public function handle(BlueprintGenerator $blueprints, CourseBuilder $builder): void
    {
        $generation = CourseGeneration::with('schemaVersion')->find($this->generationId);

        if ($generation === null || $generation->status !== CourseGeneration::PENDING) {
            return;
        }

        $generation->update(['status' => CourseGeneration::PROCESSING]);

        try {
            $result = $blueprints->generate($generation);
            $actor = User::findOrFail($generation->requested_by);

            $course = $builder->build($result['blueprint'], $generation->schemaVersion, $generation->name, $actor);

            $generation->update([
                'status' => CourseGeneration::COMPLETED,
                'course_id' => $course->id,
                'input_tokens' => $result['inputTokens'],
                'output_tokens' => $result['outputTokens'],
            ]);

            // Only drop the source PDF once we've succeeded — a failed run keeps
            // it so the author can retry without re-uploading.
            $this->cleanupPdf($generation);
        } catch (Throwable $e) {
            report($e);
            $generation->update(['status' => CourseGeneration::FAILED, 'error' => Str::limit($e->getMessage(), 500)]);
        }
    }

    public function failed(Throwable $e): void
    {
        $generation = CourseGeneration::find($this->generationId);
        $generation?->update(['status' => CourseGeneration::FAILED, 'error' => Str::limit($e->getMessage(), 500)]);
    }

    private function cleanupPdf(CourseGeneration $generation): void
    {
        if ($generation->pdf_path !== null) {
            Storage::disk(config('filesystems.default'))->delete($generation->pdf_path);
            $generation->update(['pdf_path' => null]);
        }
    }
}

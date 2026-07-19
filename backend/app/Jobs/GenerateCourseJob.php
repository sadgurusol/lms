<?php

namespace App\Jobs;

use App\Models\CourseGeneration;
use App\Models\User;
use App\Services\Generation\BlueprintGenerator;
use App\Services\Generation\CourseBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Orchestrates a course generation: ask the model for the STRUCTURE (one quick
 * call), build the draft course tree from it, then hand off to a chain of
 * {@see GenerateContentJob}s — one short job per topic — to fill teaching
 * content. Splitting the work keeps every job well under any queue timeout, so
 * the total length of a big course no longer matters. Never auto-retries; a
 * failed run is retried from the studio and *resumes* (the structure is kept).
 * See docs/14-course-generation.md.
 */
class GenerateCourseJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public readonly string $generationId)
    {
        // Only the outline call runs here (content is chained), so this is short.
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
            // Resume: if the structure was already built (e.g. a retry after the
            // content chain failed), skip straight to filling remaining topics.
            if ($generation->course_id === null) {
                $generation->update(['error' => null, 'input_tokens' => 0, 'output_tokens' => 0]);

                $outline = $blueprints->outline($generation);
                $actor = User::findOrFail($generation->requested_by);
                $course = $builder->build($outline['blueprint'], $generation->schemaVersion, $generation->name, $actor);

                $generation->update(['course_id' => $course->id]);
                $generation->increment('input_tokens', $outline['inputTokens']);
                $generation->increment('output_tokens', $outline['outputTokens']);
            }

            GenerateContentJob::dispatch($generation->id);
        } catch (Throwable $e) {
            report($e);
            // Keep the PDF (if any) so a retry can resume without re-uploading.
            $generation->update(['status' => CourseGeneration::FAILED, 'error' => Str::limit($e->getMessage(), 500)]);
        }
    }

    public function failed(Throwable $e): void
    {
        $generation = CourseGeneration::find($this->generationId);
        $generation?->update(['status' => CourseGeneration::FAILED, 'error' => Str::limit($e->getMessage(), 500)]);
    }
}

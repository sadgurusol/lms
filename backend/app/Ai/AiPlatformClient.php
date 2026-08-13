<?php

namespace App\Ai;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Client for the shared Samchita AI Platform (Python/FastAPI). The platform is
 * provider-agnostic and enriches lessons with images, formulas, simulations,
 * animations and an animated-reveal breakdown.
 *
 * Unlike the ischool client (which flattens the block schema to markdown), the
 * LMS keeps the native block shape: a Step is
 *   { step_number, step_type, title, voice_script, blocks:[…], animation?:{…} }
 * mapped 1:1 onto CourseNodes + ContentBlocks (docs/14 §5).
 *
 * Flow: POST /v1/lessons/generate → { job_id } → poll GET /v1/jobs/{id} until
 * completed, then return the result's `steps`.
 */
class AiPlatformClient
{
    /** Only active when explicitly enabled and both URL + key are present. */
    public function isEnabled(): bool
    {
        return (bool) config('services.ai_platform.enabled')
            && (bool) config('services.ai_platform.url')
            && (bool) config('services.ai_platform.key');
    }

    /**
     * Generate a full lesson's steps (native block shape).
     *
     * @param  array<string, mixed>  $context  topic|grade_level|subject|chapter|objectives|content|instructions
     * @return array<int, array<string, mixed>>  the platform's `steps`
     */
    public function generateLesson(array $context): array
    {
        $payload = array_filter([
            'topic' => $context['topic'] ?? null,
            'grade_level' => $context['grade_level'] ?? null,
            'subject' => $context['subject'] ?? null,
            'chapter' => $context['chapter'] ?? null,
            'objectives' => $context['objectives'] ?? [],
            'content' => $context['content'] ?? null,
            'instructions' => $context['instructions'] ?? null,
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);

        $jobId = $this->enqueue($payload, '/v1/lessons/generate');
        $result = $this->poll($jobId);

        return array_values(array_filter(
            $result['steps'] ?? [],
            fn ($s) => is_array($s),
        ));
    }

    /**
     * Enqueue an interactive "next step" job (the studio lesson builder). Returns
     * the platform job id to poll via stepResult().
     *
     * @param  array<string, mixed>  $context  topic|grade_level|subject|chapter|objectives|content|instructions
     * @param  array<int, array<string, mixed>>  $priorSteps  already-accepted steps (native shape)
     */
    public function startStep(array $context, array $priorSteps, ?int $stepNumber, ?int $targetSteps, bool $animated, ?string $feedback = null): string
    {
        $payload = $this->contextPayload($context) + array_filter([
            'prior_steps' => $this->priorStepsPayload($priorSteps),
            'step_number' => $stepNumber,
            'target_steps' => $targetSteps,
            'animated' => $animated,
            'feedback' => $feedback,
        ], fn ($v) => $v !== null);

        return $this->enqueue($payload, '/v1/lessons/step');
    }

    /**
     * Enqueue an interactive "revise this step" job.
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $step
     * @param  array<int, array<string, mixed>>  $priorSteps
     */
    public function startReviseStep(array $context, array $step, string $feedback, array $priorSteps, bool $animated): string
    {
        $payload = $this->contextPayload($context) + [
            'prior_steps' => $this->priorStepsPayload($priorSteps),
            'revise_step' => [
                'step_number' => (int) ($step['step_number'] ?? 1),
                'step_type' => $step['step_type'] ?? 'concept',
                'title' => $step['title'] ?? '',
                'voice_script' => $step['voice_script'] ?? '',
                'content' => $this->stepText($step),
            ],
            'feedback' => $feedback,
            'animated' => $animated,
        ];

        return $this->enqueue($payload, '/v1/lessons/step');
    }

    /**
     * Poll a step job once (non-blocking). Returns the status and, when complete,
     * the single native step and the is_last flag.
     *
     * @return array{status: string, step: ?array<string, mixed>, is_last: bool, error: ?string}
     */
    public function stepResult(string $jobId): array
    {
        $res = $this->http()->get($this->url("/v1/jobs/{$jobId}"));
        if (! $res->successful()) {
            throw new RuntimeException('AI Platform poll failed ('.$res->status().'): '.$res->body());
        }

        $status = $res->json('status');

        if ($status === 'completed') {
            $result = $res->json('result') ?? [];
            $steps = $result['steps'] ?? [];

            return [
                'status' => 'completed',
                'step' => is_array($steps[0] ?? null) ? $steps[0] : null,
                'is_last' => (bool) ($result['is_last'] ?? false),
                'error' => null,
            ];
        }

        if ($status === 'failed') {
            return ['status' => 'failed', 'step' => null, 'is_last' => false, 'error' => $res->json('error') ?? 'unknown error'];
        }

        return ['status' => $status ?? 'processing', 'step' => null, 'is_last' => false, 'error' => null];
    }

    /** @param array<string, mixed> $context */
    private function contextPayload(array $context): array
    {
        return array_filter([
            'topic' => $context['topic'] ?? null,
            'grade_level' => $context['grade_level'] ?? null,
            'subject' => $context['subject'] ?? null,
            'chapter' => $context['chapter'] ?? null,
            'objectives' => $context['objectives'] ?? [],
            'content' => $context['content'] ?? null,
            'instructions' => $context['instructions'] ?? null,
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    /**
     * The light context the platform needs for continuity between steps.
     *
     * @param  array<int, array<string, mixed>>  $priorSteps
     * @return array<int, array<string, mixed>>
     */
    private function priorStepsPayload(array $priorSteps): array
    {
        return array_values(array_map(fn ($s, $i) => [
            'step_number' => (int) ($s['step_number'] ?? $i + 1),
            'step_type' => $s['step_type'] ?? 'concept',
            'title' => $s['title'] ?? '',
            'voice_script' => $s['voice_script'] ?? '',
            'content' => $this->stepText($s),
        ], $priorSteps, array_keys($priorSteps)));
    }

    /** A short text summary of a native step (title + first text block). */
    private function stepText(array $step): string
    {
        foreach (($step['blocks'] ?? []) as $b) {
            if (is_array($b) && ($b['type'] ?? null) === 'text' && ! empty($b['markdown'])) {
                return (string) $b['markdown'];
            }
        }

        return (string) ($step['title'] ?? '');
    }

    private function enqueue(array $payload, string $path): string
    {
        $res = $this->http()->post($this->url($path), $payload);

        if (! $res->successful()) {
            throw new RuntimeException('AI Platform enqueue failed ('.$res->status().'): '.$res->body());
        }

        $jobId = $res->json('job_id');
        if (! $jobId) {
            throw new RuntimeException('AI Platform did not return a job_id.');
        }

        return $jobId;
    }

    /** Poll the async job until it completes, fails, or we hit the timeout. */
    private function poll(string $jobId): array
    {
        $deadline = microtime(true) + (float) config('services.ai_platform.timeout', 300);
        $interval = (float) config('services.ai_platform.poll_interval', 2);

        while (microtime(true) < $deadline) {
            $res = $this->http()->get($this->url("/v1/jobs/{$jobId}"));
            if (! $res->successful()) {
                throw new RuntimeException('AI Platform poll failed ('.$res->status().'): '.$res->body());
            }

            $status = $res->json('status');
            if ($status === 'completed') {
                return $res->json('result') ?? [];
            }
            if ($status === 'failed') {
                throw new RuntimeException('AI Platform job failed: '.($res->json('error') ?? 'unknown error'));
            }

            usleep((int) ($interval * 1_000_000));
        }

        throw new RuntimeException("AI Platform job {$jobId} did not complete within the timeout.");
    }

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withHeaders([
            'X-API-Key' => config('services.ai_platform.key'),
            'Content-Type' => 'application/json',
        ])->timeout(30);
    }

    private function url(string $path): string
    {
        $base = rtrim((string) config('services.ai_platform.url'), '/');
        // Tolerate a base URL that already includes the /v1 prefix.
        $base = preg_replace('#/v1$#', '', $base);

        return $base.$path;
    }
}

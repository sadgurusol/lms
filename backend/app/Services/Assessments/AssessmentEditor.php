<?php

namespace App\Services\Assessments;

use App\Models\Assessment;
use App\Models\AssessmentQuestion;
use App\Models\CourseNode;
use App\Models\Question;
use App\Support\FractionalIndex;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Assemble an assessment: its questions, their order and their marks.
 *
 * The denormalised total_points is kept in step after every change through
 * Assessment::syncTotalPoints — the learner UI and the pass calculation read it,
 * and a stale total is a wrong grade.
 */
final class AssessmentEditor
{
    public function create(CourseNode $node, string $kind, string $title): Assessment
    {
        return Assessment::create([
            'course_id' => $node->course_id,
            'course_node_id' => $node->id,
            'kind' => $kind,
            'title' => $title,
            'settings' => [],
        ]);
    }

    /**
     * Add a question to the assessment. Points default to the question's own
     * default; the assembler can override per assessment (the same question is
     * worth more on a final than in a warm-up).
     */
    public function addQuestion(Assessment $assessment, Question $question, ?float $points = null): AssessmentQuestion
    {
        return DB::transaction(function () use ($assessment, $question, $points) {
            if ($assessment->assessmentQuestions()->where('question_id', $question->id)->exists()) {
                throw new RuntimeException('That question is already in this assessment.');
            }

            $link = AssessmentQuestion::create([
                'assessment_id' => $assessment->id,
                'question_id' => $question->id,
                'points' => $points ?? (float) $question->default_points,
                'sort_key' => $this->appendKey($assessment),
            ]);

            $assessment->syncTotalPoints();

            return $link;
        });
    }

    public function setPoints(AssessmentQuestion $link, float $points): AssessmentQuestion
    {
        DB::transaction(function () use ($link, $points) {
            $link->update(['points' => $points]);
            $link->assessment->syncTotalPoints();
        });

        return $link->refresh();
    }

    public function reorder(AssessmentQuestion $link, ?string $afterId): AssessmentQuestion
    {
        $link->update(['sort_key' => $this->keyAfter($link->assessment, $afterId, $link->id)]);

        return $link->refresh();
    }

    public function removeQuestion(AssessmentQuestion $link): void
    {
        DB::transaction(function () use ($link) {
            $assessment = $link->assessment;
            $link->delete();
            $assessment->syncTotalPoints();
        });
    }

    /** @param array<string, mixed> $settings */
    public function updateSettings(Assessment $assessment, string $title, array $settings): Assessment
    {
        $assessment->update(['title' => $title, 'settings' => $settings]);

        return $assessment->refresh();
    }

    public function delete(Assessment $assessment): void
    {
        $assessment->delete();
    }

    private function appendKey(Assessment $assessment): string
    {
        $last = $assessment->assessmentQuestions()
            ->orderByDesc('sort_key')
            ->value('sort_key');

        return FractionalIndex::between($last, null);
    }

    private function keyAfter(Assessment $assessment, ?string $afterId, string $excludeId): string
    {
        $siblings = AssessmentQuestion::query()
            ->where('assessment_id', $assessment->id)
            ->whereKeyNot($excludeId)
            ->orderBy('sort_key')
            ->pluck('sort_key', 'id');

        if ($afterId === null) {
            return FractionalIndex::between(null, $siblings->first());
        }

        $keys = $siblings->values()->all();
        $ids = $siblings->keys()->all();
        $index = array_search($afterId, $ids, true);

        if ($index === false) {
            throw new RuntimeException('That question is not in this assessment.');
        }

        return FractionalIndex::between($keys[$index], $keys[$index + 1] ?? null);
    }
}

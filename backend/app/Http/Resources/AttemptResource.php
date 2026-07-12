<?php

namespace App\Http\Resources;

use App\Models\AssessmentAttempt;
use App\Models\AssessmentQuestion;
use App\Models\AttemptAnswer;

/**
 * A learner's own attempt, presented safely.
 *
 * Mid-attempt: questions in the frozen order, options shuffled per attempt, and
 * NO answer key (QuestionViewerResource::forAttempt enforces that). After
 * grading: the score, and the key *only* when the assessment's show_answers
 * setting permits it. Invariant I14 (no answer leaks before submission) rides on
 * this class choosing the right resource.
 */
final class AttemptResource
{
    /** @return array<string, mixed> */
    public static function forLearner(AssessmentAttempt $attempt): array
    {
        $attempt->loadMissing('assessment', 'answers');
        $settings = $attempt->assessment->config();
        $graded = $attempt->state === AssessmentAttempt::GRADED;

        // Reveal the key only after grading, and only if the setting allows it.
        $revealAnswers = $graded && $settings->mayRevealAnswers((bool) $attempt->passed);

        /** @var array<string, AssessmentQuestion> $questions */
        $questions = AssessmentQuestion::whereIn('id', $attempt->question_order)
            ->with('question.options')
            ->get()
            ->keyBy('id')
            ->all();

        /** @var array<string, AttemptAnswer> $answers */
        $answers = $attempt->answers->keyBy('assessment_question_id')->all();

        $items = [];

        // question_order is authoritative and frozen; walk it, not the relation.
        foreach ($attempt->question_order as $aqId) {
            $aq = $questions[$aqId] ?? null;
            if ($aq === null) {
                continue;
            }

            $points = (float) $aq->points;
            $answer = $answers[$aqId] ?? null;

            $item = [
                'assessment_question_id' => $aqId,
                'response' => $answer?->response,
                'question' => $revealAnswers
                    ? QuestionViewerResource::withAnswerKey($aq->question, $points)
                    : QuestionViewerResource::forAttempt($aq->question, $attempt, $points),
            ];

            // The per-question breakdown follows the same reveal rule as the key:
            // "never show answers" means the learner sees the overall score only,
            // not which questions they got wrong.
            if ($revealAnswers && $answer !== null) {
                $item['is_correct'] = $answer->is_correct;
                $item['points_awarded'] = $answer->points_awarded === null ? null : (float) $answer->points_awarded;
            }

            $items[] = $item;
        }

        return [
            'id' => $attempt->id,
            'assessment' => [
                'id' => $attempt->assessment_id,
                'title' => $attempt->assessment->title,
                'kind' => $attempt->assessment->kind,
            ],
            'state' => $attempt->state,
            'attempt_number' => $attempt->attempt_number,
            'started_at' => $attempt->started_at->toIso8601String(),
            'expires_at' => $attempt->expires_at?->toIso8601String(),
            'submitted_at' => $attempt->submitted_at?->toIso8601String(),
            'graded_at' => $attempt->graded_at?->toIso8601String(),
            // The client renders a "you can't go back" UI from this, but the
            // server is the authority — RecordAnswer refuses a backward answer.
            'allow_backtrack' => $settings->allowBacktrack,
            'max_index_reached' => $attempt->max_index_reached,
            'score' => $attempt->score === null ? null : (float) $attempt->score,
            'max_score' => $attempt->max_score === null ? null : (float) $attempt->max_score,
            'passed' => $attempt->passed,
            'answers_revealed' => $revealAnswers,
            'questions' => $items,
        ];
    }
}

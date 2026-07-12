<?php

namespace App\Services\Assessments;

use App\Models\Question;
use App\Models\QuestionBank;
use App\Models\QuestionOption;
use App\Models\User;
use App\Support\FractionalIndex;
use Illuminate\Support\Facades\DB;

/**
 * Persists a question and its options as one unit.
 *
 * The controller has already validated shape per type; this class only writes.
 * Options are replaced wholesale on update — a question's option set is small and
 * rewriting it is simpler (and safer) than diffing, since answer keys ride on the
 * rows and a stale key is a mis-grade.
 */
final class QuestionEditor
{
    /** The types the studio can author today; the rest need richer editors. */
    public const AUTHORABLE = ['mcq_single', 'mcq_multi', 'true_false', 'short_answer', 'numeric', 'essay'];

    /**
     * @param  array{type: string, stem: array<string,mixed>, explanation: array<string,mixed>|null, points: float, grading: array<string,mixed>, options: list<array{text: string, correct: bool}>}  $payload
     */
    public function create(QuestionBank $bank, array $payload, ?User $actor): Question
    {
        return DB::transaction(function () use ($bank, $payload, $actor) {
            $question = Question::create([
                'question_bank_id' => $bank->id,
                'type' => $payload['type'],
                'stem' => $payload['stem'],
                'explanation' => $payload['explanation'],
                'default_points' => $payload['points'],
                'grading' => $payload['grading'],
                'created_by' => $actor?->id,
            ]);

            $this->syncOptions($question, $payload['options']);

            return $question->load('options');
        });
    }

    /**
     * @param  array{stem: array<string,mixed>, explanation: array<string,mixed>|null, points: float, grading: array<string,mixed>, options: list<array{text: string, correct: bool}>}  $payload
     */
    public function update(Question $question, array $payload): Question
    {
        return DB::transaction(function () use ($question, $payload) {
            // Type is fixed once created: changing it would strand the answer key
            // and any attempt already recorded against it.
            $question->update([
                'stem' => $payload['stem'],
                'explanation' => $payload['explanation'],
                'default_points' => $payload['points'],
                'grading' => $payload['grading'],
            ]);

            $this->syncOptions($question, $payload['options']);

            return $question->load('options');
        });
    }

    public function delete(Question $question): void
    {
        $question->delete();
    }

    /**
     * @param  list<array{text: string, correct: bool}>  $options
     */
    private function syncOptions(Question $question, array $options): void
    {
        // A type with no options (true_false, numeric, short_answer, essay) sends
        // an empty list, which clears any left behind — a defensive no-op on create.
        $question->options()->delete();

        if ($options === []) {
            return;
        }

        $keys = FractionalIndex::sequence(count($options));

        foreach ($options as $i => $option) {
            QuestionOption::create([
                'question_id' => $question->id,
                'body' => ['text' => $option['text']],
                'is_correct' => $option['correct'],
                'sort_key' => $keys[$i],
            ]);
        }
    }
}

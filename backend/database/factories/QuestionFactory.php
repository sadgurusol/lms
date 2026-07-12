<?php

namespace Database\Factories;

use App\Assessments\QuestionType;
use App\Models\Question;
use App\Models\QuestionBank;
use App\Models\QuestionOption;
use App\Support\FractionalIndex;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Question> */
class QuestionFactory extends Factory
{
    protected $model = Question::class;

    public function definition(): array
    {
        return [
            'question_bank_id' => QuestionBank::factory(),
            'type' => QuestionType::McqSingle->value,
            'stem' => ['format' => 'portable_text', 'body' => []],
            'explanation' => ['format' => 'portable_text', 'body' => []],
            'default_points' => 1,
            'grading' => [],
            'tags' => [],
        ];
    }

    public function ofType(QuestionType $type, array $grading = []): static
    {
        return $this->state(fn () => ['type' => $type->value, 'grading' => $grading]);
    }

    /**
     * @param  list<array{text: string, correct?: bool, match_key?: string}>  $options
     */
    public function withOptions(array $options): static
    {
        return $this->afterCreating(function (Question $question) use ($options) {
            // Not sprintf('a%02d'): that mints a00, a10, a20 — keys ending in
            // '0', which no key may (see the sort_key CHECK constraint).
            $keys = FractionalIndex::sequence(count($options));

            foreach ($options as $i => $option) {
                QuestionOption::create([
                    'question_id' => $question->id,
                    'body' => ['text' => $option['text']],
                    'is_correct' => $option['correct'] ?? false,
                    'match_key' => $option['match_key'] ?? null,
                    'sort_key' => $keys[$i],
                ]);
            }
        });
    }
}

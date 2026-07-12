<?php

namespace App\Http\Controllers\Studio;

use App\Assessments\QuestionType;
use App\Http\Controllers\Controller;
use App\Models\Question;
use App\Models\QuestionBank;
use App\Services\Assessments\QuestionEditor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Authoring questions inside a bank. Answer keys are shaped per type, exactly as
 * the matching Grader reads them (see App\Assessments\Grading), so a question
 * saved here grades correctly with no translation layer.
 */
class QuestionController extends Controller
{
    public function store(Request $request, QuestionBank $bank, QuestionEditor $editor): RedirectResponse
    {
        Gate::authorize('manage', $bank);

        $type = QuestionType::from($this->validatedType($request));
        $payload = $this->validatePayload($request, $type);

        $editor->create($bank, ['type' => $type->value, ...$payload], $request->user());

        return back()->with('success', 'Question added.');
    }

    public function update(Request $request, Question $question, QuestionEditor $editor): RedirectResponse
    {
        Gate::authorize('manage', $question->questionBank);

        // Type is immutable; validate against the question's existing type.
        $payload = $this->validatePayload($request, $question->questionType());

        $editor->update($question, $payload);

        return back()->with('success', 'Question saved.');
    }

    public function destroy(Request $request, Question $question, QuestionEditor $editor): RedirectResponse
    {
        Gate::authorize('manage', $question->questionBank);

        $editor->delete($question);

        return back()->with('success', 'Question removed.');
    }

    private function validatedType(Request $request): string
    {
        return $request->validate([
            'type' => ['required', Rule::in(QuestionEditor::AUTHORABLE)],
        ])['type'];
    }

    /**
     * Validate the shared fields and the per-type answer key, then normalise into
     * the shape QuestionEditor persists.
     *
     * @return array{stem: array<string,mixed>, explanation: array<string,mixed>|null, points: float, grading: array<string,mixed>, options: list<array{text: string, correct: bool}>}
     */
    private function validatePayload(Request $request, QuestionType $type): array
    {
        $data = $request->validate([
            'stem' => ['required', 'string', 'max:5000'],
            'explanation' => ['nullable', 'string', 'max:5000'],
            'points' => ['required', 'numeric', 'min:0.1', 'max:1000'],
        ]);

        [$grading, $options] = $this->answerKey($request, $type);

        return [
            'stem' => $this->portableText($data['stem']),
            'explanation' => ($data['explanation'] ?? '') === ''
                ? null
                : $this->portableText($data['explanation']),
            'points' => (float) $data['points'],
            'grading' => $grading,
            'options' => $options,
        ];
    }

    /**
     * The answer key, shaped for the type's Grader.
     *
     * @return array{0: array<string,mixed>, 1: list<array{text: string, correct: bool}>}
     */
    private function answerKey(Request $request, QuestionType $type): array
    {
        return match ($type) {
            QuestionType::McqSingle => [[], $this->mcqOptions($request, exactlyOne: true)],
            QuestionType::McqMulti => [
                ['scoring' => $request->validate([
                    'scoring' => ['required', Rule::in(['all_or_nothing', 'partial'])],
                ])['scoring']],
                $this->mcqOptions($request, exactlyOne: false),
            ],
            QuestionType::TrueFalse => [
                ['answer' => $request->validate(['answer' => ['required', 'boolean']])['answer']],
                [],
            ],
            QuestionType::Numeric => [
                $request->validate([
                    'answer' => ['required', 'numeric'],
                    'tolerance' => ['required', 'numeric', 'min:0'],
                ]),
                [],
            ],
            QuestionType::ShortAnswer => [$this->shortAnswerKey($request), []],
            QuestionType::Essay => [[], []],
            default => throw ValidationException::withMessages(['type' => 'That question type cannot be authored here.']),
        };
    }

    /**
     * @return list<array{text: string, correct: bool}>
     */
    private function mcqOptions(Request $request, bool $exactlyOne): array
    {
        $data = $request->validate([
            'options' => ['required', 'array', 'min:2', 'max:12'],
            'options.*.text' => ['required', 'string', 'max:1000'],
            'options.*.correct' => ['required', 'boolean'],
        ]);

        $options = array_map(
            fn (array $o) => ['text' => $o['text'], 'correct' => (bool) $o['correct']],
            $data['options'],
        );

        $correct = array_filter($options, fn (array $o) => $o['correct']);

        if ($exactlyOne && count($correct) !== 1) {
            throw ValidationException::withMessages([
                'options' => 'Mark exactly one option correct.',
            ]);
        }

        if (! $exactlyOne && count($correct) < 1) {
            throw ValidationException::withMessages([
                'options' => 'Mark at least one option correct.',
            ]);
        }

        return $options;
    }

    /** @return array<string, mixed> */
    private function shortAnswerKey(Request $request): array
    {
        $data = $request->validate([
            'accept' => ['required', 'array', 'min:1', 'max:20'],
            'accept.*' => ['required', 'string', 'max:200'],
            'fuzzy' => ['required', 'boolean'],
            'max_distance' => ['required_if:fuzzy,true', 'integer', 'min:1', 'max:5'],
        ]);

        return [
            'accept' => array_values($data['accept']),
            'fuzzy' => (bool) $data['fuzzy'],
            // Only meaningful when fuzzy; default 2, matching the grader.
            'max_distance' => (int) ($data['max_distance'] ?? 2),
        ];
    }

    /**
     * Wrap plain text as Portable Text — the same document shape a rich_text block
     * uses — so a richer editor later reads and writes the same stems.
     *
     * @return array<string, mixed>
     */
    private function portableText(string $text): array
    {
        $body = [];

        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }

            $body[] = [
                '_type' => 'block',
                'style' => 'normal',
                'markDefs' => [],
                'children' => [['_type' => 'span', 'text' => $line, 'marks' => []]],
            ];
        }

        return ['format' => 'portable_text', 'body' => $body];
    }
}

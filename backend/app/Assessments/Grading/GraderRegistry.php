<?php

namespace App\Assessments\Grading;

use App\Assessments\QuestionType;

final class GraderRegistry
{
    /** @var array<string, class-string<Grader>> */
    private const GRADERS = [
        QuestionType::McqSingle->value => McqSingleGrader::class,
        QuestionType::McqMulti->value => McqMultiGrader::class,
        QuestionType::TrueFalse->value => TrueFalseGrader::class,
        QuestionType::Numeric->value => NumericGrader::class,
        QuestionType::FillBlank->value => FillBlankGrader::class,
        QuestionType::Match->value => MatchGrader::class,
        QuestionType::Ordering->value => OrderingGrader::class,
        QuestionType::ShortAnswer->value => ShortAnswerGrader::class,
        QuestionType::Essay->value => EssayGrader::class,
    ];

    /** @var array<string, Grader> */
    private array $resolved = [];

    public function for(QuestionType $type): Grader
    {
        return $this->resolved[$type->value] ??= new (self::GRADERS[$type->value]);
    }

    /**
     * Every question type must have a grader.
     *
     * @return list<string>
     */
    public static function coveredTypes(): array
    {
        return array_keys(self::GRADERS);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Authorization\Permissions;
use App\Http\Controllers\Controller;
use App\Models\Question;
use App\Services\Assessments\QuestionStats;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuestionStatsController extends Controller
{
    public function show(Request $request, Question $question, QuestionStats $stats): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::QUESTION_MANAGE), 403);

        $data = $stats->for($question->id);

        // No graded attempts yet is not an error; it is a question nobody has
        // answered. Say so rather than 404ing a question that plainly exists.
        return response()->json($data ?? [
            'question_id' => $question->id,
            'attempts' => 0,
            'facility' => null,
            'discrimination' => null,
            'flags' => [],
        ]);
    }
}

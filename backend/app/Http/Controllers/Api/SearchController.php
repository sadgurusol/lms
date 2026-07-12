<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Search\SearchCourses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __invoke(Request $request, SearchCourses $search): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:200'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $results = $search->handle(
            $request->user(),
            $data['q'],
            $request->user()->currentClientId(),
            $data['limit'] ?? 25,
        );

        return response()->json(['data' => $results]);
    }
}

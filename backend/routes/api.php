<?php

use App\Http\Controllers\Api\MyCoursesController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->prefix('v1')->group(function () {
    Route::get('/me/courses', [MyCoursesController::class, 'index']);
    Route::get('/me/courses/{course}/content', [MyCoursesController::class, 'content']);
});

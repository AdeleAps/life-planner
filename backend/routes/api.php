<?php

use App\Http\Controllers\Api\V1\TaskController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

Route::get('/v1/health', static function (): JsonResponse {
    return response()->json(['status' => 'ok']);
})->name('api.v1.health');

Route::get('/v1/tasks', [TaskController::class, 'index'])->name('api.v1.tasks.index');
Route::post('/v1/tasks', [TaskController::class, 'store'])->name('api.v1.tasks.store');

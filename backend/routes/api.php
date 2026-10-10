<?php

use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\TaskCompletionController;
use App\Http\Controllers\Api\V1\TaskController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

Route::get('/v1/health', static function (): JsonResponse {
    return response()->json(['status' => 'ok']);
})->name('api.v1.health');

Route::get('/v1/tasks', [TaskController::class, 'index'])->name('api.v1.tasks.index');
Route::post('/v1/tasks', [TaskController::class, 'store'])->name('api.v1.tasks.store');
Route::put('/v1/tasks/{task}', [TaskController::class, 'update'])->name('api.v1.tasks.update');
Route::get('/v1/tasks/{task}/completions', [TaskCompletionController::class, 'index'])->name('api.v1.tasks.completions.index');
Route::post('/v1/tasks/{task}/completions', [TaskCompletionController::class, 'store'])->name('api.v1.tasks.completions.store');
Route::patch('/v1/tasks/{task}/project', [TaskController::class, 'attachToProject'])->name('api.v1.tasks.attach-to-project');
Route::delete('/v1/tasks/{task}/project', [TaskController::class, 'removeFromProject'])->name('api.v1.tasks.remove-from-project');
Route::delete('/v1/tasks/{task}', [TaskController::class, 'destroy'])->name('api.v1.tasks.destroy');
Route::get('/v1/projects', [ProjectController::class, 'index'])->name('api.v1.projects.index');
Route::post('/v1/projects', [ProjectController::class, 'store'])->name('api.v1.projects.store');
Route::put('/v1/projects/{project}', [ProjectController::class, 'update'])->name('api.v1.projects.update');
Route::delete('/v1/projects/{project}', [ProjectController::class, 'destroy'])->name('api.v1.projects.destroy');

<?php

use App\Http\Controllers\Api\V1\AdvanceOfferController;
use App\Http\Controllers\Api\V1\CalendarController;
use App\Http\Controllers\Api\V1\DevResetController;
use App\Http\Controllers\Api\V1\PlanningDayInputController;
use App\Http\Controllers\Api\V1\PlanningDraftController;
use App\Http\Controllers\Api\V1\PlanningFocusController;
use App\Http\Controllers\Api\V1\PlanningPlanController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\PushSubscriptionController;
use App\Http\Controllers\Api\V1\ScheduledTaskController;
use App\Http\Controllers\Api\V1\TaskCompletionController;
use App\Http\Controllers\Api\V1\TaskController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

Route::get('/v1/health', static function (): JsonResponse {
    return response()->json(['status' => 'ok']);
})->name('api.v1.health');

Route::get('/v1/calendar', [CalendarController::class, 'index'])->name('api.v1.calendar.index');
Route::get('/v1/calendar/bootstrap', [CalendarController::class, 'bootstrap'])->name('api.v1.calendar.bootstrap');
Route::post('/v1/calendar/days-off', [CalendarController::class, 'storeDayOff'])->name('api.v1.calendar.days-off.store');
Route::put('/v1/calendar/days-off/{dayOffPeriod}', [CalendarController::class, 'updateDayOff'])->name('api.v1.calendar.days-off.update');
Route::delete('/v1/calendar/days-off/{dayOffPeriod}', [CalendarController::class, 'destroyDayOff'])->name('api.v1.calendar.days-off.destroy');

Route::get('/v1/focuses', [PlanningFocusController::class, 'index'])->name('api.v1.focuses.index');
Route::get('/v1/advance-offers', [AdvanceOfferController::class, 'index'])->name('api.v1.advance-offers.index');
Route::post('/v1/advance-offers/refresh', [AdvanceOfferController::class, 'refresh'])->middleware('throttle:10,1')->name('api.v1.advance-offers.refresh');
Route::post('/v1/advance-offers/{offer}/accept', [AdvanceOfferController::class, 'accept'])->name('api.v1.advance-offers.accept');
Route::post('/v1/advance-offers/{offer}/decline', [AdvanceOfferController::class, 'decline'])->name('api.v1.advance-offers.decline');
Route::post('/v1/focuses/tomorrow-prompts', [PlanningFocusController::class, 'claimTomorrowPrompts'])->name('api.v1.focuses.tomorrow-prompts');
Route::put('/v1/focuses/{kind}/{startsOn}', [PlanningFocusController::class, 'update'])->name('api.v1.focuses.update');

Route::get('/v1/push/configuration', [PushSubscriptionController::class, 'configuration'])->name('api.v1.push.configuration');
Route::post('/v1/push/subscriptions', [PushSubscriptionController::class, 'store'])->middleware('throttle:10,1')->name('api.v1.push.subscriptions.store');
Route::delete('/v1/push/subscriptions', [PushSubscriptionController::class, 'destroy'])->middleware('throttle:10,1')->name('api.v1.push.subscriptions.destroy');

Route::get('/v1/tasks', [TaskController::class, 'index'])->name('api.v1.tasks.index');
Route::get('/v1/planning-days/{date}/setup', [PlanningDayInputController::class, 'show'])->name('api.v1.planning-days.setup.show');
Route::put('/v1/planning-days/{date}/setup', [PlanningDayInputController::class, 'update'])->name('api.v1.planning-days.setup.update');
Route::post('/v1/planning-days/{date}/draft', PlanningDraftController::class)->name('api.v1.planning-days.draft');
Route::get('/v1/planning-days/{date}/plan', [PlanningPlanController::class, 'show'])->name('api.v1.planning-days.plan.show');
Route::post('/v1/planning-days/{date}/plan', [PlanningPlanController::class, 'store'])->name('api.v1.planning-days.plan.store');
Route::get('/v1/planning-days/{date}/tasks', [ScheduledTaskController::class, 'index'])->name('api.v1.planning-days.tasks.index');
Route::patch('/v1/scheduled-tasks/{scheduledTask}', [ScheduledTaskController::class, 'update'])->name('api.v1.scheduled-tasks.update');
Route::delete('/v1/scheduled-tasks/{scheduledTask}', [ScheduledTaskController::class, 'destroy'])->name('api.v1.scheduled-tasks.destroy');
Route::get('/v1/planning-review/pending', [ScheduledTaskController::class, 'pendingReview'])->name('api.v1.planning-review.pending');
Route::post('/v1/dev/reset', DevResetController::class)->name('api.v1.dev.reset');
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

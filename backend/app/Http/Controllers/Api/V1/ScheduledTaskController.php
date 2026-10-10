<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ScheduledTaskResource;
use App\Models\ScheduledTask;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ScheduledTaskController extends Controller
{
    public function pendingReview(): AnonymousResourceCollection
    {
        $today = CarbonImmutable::now('Europe/Riga')->toDateString();

        $scheduledTasks = ScheduledTask::query()
            ->where('plan_date', '<=', $today)
            ->where('status', 'planned')
            ->whereHas('task', fn (Builder $query): Builder => $query->where('lifecycle_state', 'active'))
            ->with('task:id,title,type,recurrence_type,is_multi_session,lifecycle_state,revision')
            ->orderBy('plan_date')
            ->orderBy('id')
            ->get();

        return ScheduledTaskResource::collection($scheduledTasks);
    }

    public function index(string $date): AnonymousResourceCollection
    {
        Validator::make(['date' => $date], ['date' => ['required', 'date_format:Y-m-d']])->validate();

        $scheduledTasks = ScheduledTask::query()
            ->where('plan_date', $date)
            ->with('task:id,title,type,recurrence_type,is_multi_session,lifecycle_state,revision')
            ->orderBy('id')
            ->get();

        return ScheduledTaskResource::collection($scheduledTasks);
    }

    public function update(Request $request, ScheduledTask $scheduledTask): JsonResponse
    {
        $values = $request->validate(['plan_date' => ['required', 'date_format:Y-m-d']]);
        $newDate = $values['plan_date'];
        $today = CarbonImmutable::now('Europe/Riga')->toDateString();

        $assignment = DB::transaction(function () use ($scheduledTask, $newDate, $today): ScheduledTask {
            $assignment = ScheduledTask::query()->with('task')->lockForUpdate()->findOrFail($scheduledTask->id);
            $this->assertMovable($assignment);

            if ($newDate < $today) {
                throw ValidationException::withMessages(['plan_date' => 'Choose today or a future date.']);
            }

            if ($assignment->task->deadline_is_hard && $assignment->task->deadline_on !== null
                && $newDate > $assignment->task->deadline_on->toDateString()) {
                throw ValidationException::withMessages(['plan_date' => 'This date is after the task’s hard deadline.']);
            }

            if ($newDate === $assignment->plan_date && $assignment->status === 'planned') {
                return $assignment;
            }

            if (ScheduledTask::query()->where('task_id', $assignment->task_id)
                ->where('plan_date', $newDate)->exists()) {
                throw ValidationException::withMessages(['plan_date' => 'This task is already on that day.']);
            }

            if ($assignment->status === 'not_completed') {
                return ScheduledTask::query()->create([
                    'task_id' => $assignment->task_id, 'plan_date' => $newDate,
                    'status' => 'planned', 'source' => 'manual',
                ])->load('task');
            }

            if ($assignment->source === 'advance_accepted') {
                DB::table('advance_offers')->where('task_id', $assignment->task_id)
                    ->where('proposed_on', $assignment->plan_date)->where('status', 'accepted')
                    ->update(['proposed_on' => $newDate, 'research_result' => null,
                        'notice_shortfall_days' => max(0, (int) $assignment->task->advance_notice_days
                            - (int) CarbonImmutable::now('Europe/Riga')->startOfDay()
                                ->diffInDays(CarbonImmutable::parse($newDate, 'Europe/Riga'))),
                        'updated_at' => now()]);
            } else {
                $assignment->source = 'manual';
            }

            $assignment->plan_date = $newDate;
            $assignment->save();

            return $assignment->refresh()->load('task');
        }, 3);

        return (new ScheduledTaskResource($assignment))->response()->setStatusCode(200);
    }

    public function destroy(ScheduledTask $scheduledTask): JsonResponse
    {
        DB::transaction(function () use ($scheduledTask): void {
            $assignment = ScheduledTask::query()->with('task')->lockForUpdate()->findOrFail($scheduledTask->id);
            $this->assertRemovable($assignment);

            if ($assignment->source === 'advance_accepted') {
                DB::table('advance_offers')->where('task_id', $assignment->task_id)
                    ->where('proposed_on', $assignment->plan_date)->where('status', 'accepted')
                    ->update(['status' => 'declined', 'updated_at' => now()]);
            }

            $assignment->delete();
        }, 3);

        return response()->json(['removed' => true]);
    }

    private function assertMovable(ScheduledTask $assignment): void
    {
        if (! in_array($assignment->status, ['planned', 'not_completed'], true)
            || $assignment->task?->lifecycle_state !== 'active') {
            throw ValidationException::withMessages(['scheduled_task' => 'Only unfinished tasks can be rescheduled.']);
        }

        if (in_array($assignment->source, ['dinner_groceries', 'dinner_cooking'], true)) {
            throw ValidationException::withMessages(['scheduled_task' => 'Delete a dinner task to remove it from the plan.']);
        }
    }

    private function assertRemovable(ScheduledTask $assignment): void
    {
        $this->assertMovable($assignment);

        if ($assignment->status !== 'planned') {
            throw ValidationException::withMessages(['scheduled_task' => 'A reviewed day stays in your history. Reschedule this task instead.']);
        }
    }
}

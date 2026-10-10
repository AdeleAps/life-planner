<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SavePlanningDayInputRequest;
use App\Http\Resources\PlanningDayInputResource;
use App\Http\Resources\ScheduledTaskResource;
use App\Models\PlanningDayInput;
use App\Models\ScheduledTask;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PlanningDayInputController extends Controller
{
    public function show(string $date): PlanningDayInputResource
    {
        $this->validateDate($date);

        $input = PlanningDayInput::query()->firstOrNew(['plan_date' => $date], [
            'energy_level' => 'standard',
            'day_off' => false,
            'dinner_enabled' => false,
            'dinner_dish' => null,
            'revision' => 0,
        ]);

        return $this->resourceWithScheduledTasks($input, $date);
    }

    public function update(SavePlanningDayInputRequest $request, string $date): PlanningDayInputResource
    {
        $this->validateDate($date);
        $values = $request->validated();
        $manualTaskIds = array_key_exists('manual_task_ids', $values) ? array_map('intval', $values['manual_task_ids']) : null;
        unset($values['manual_task_ids']);
        $values['dinner_dish'] = $values['dinner_enabled'] ? $values['dinner_dish'] : null;

        $input = DB::transaction(function () use ($date, $values, $manualTaskIds): PlanningDayInput {
            if ($manualTaskIds !== null) {
                $tasks = Task::query()
                    ->whereKey($manualTaskIds)
                    ->with('dependencies:id,title,lifecycle_state')
                    ->lockForUpdate()
                    ->get();

                if ($tasks->count() !== count($manualTaskIds) || $tasks->contains(fn (Task $task): bool => $task->item_type !== 'task' || $task->lifecycle_state !== 'active')) {
                    throw ValidationException::withMessages(['manual_task_ids' => 'Choose active tasks for tomorrow.']);
                }

                if ($tasks->contains(fn (Task $task): bool => ! $task->dependenciesComplete())) {
                    throw ValidationException::withMessages(['manual_task_ids' => 'Finish the dependencies before adding this task.']);
                }
            }

            $input = PlanningDayInput::query()->where('plan_date', $date)->lockForUpdate()->first();

            if ($input === null) {
                $input = PlanningDayInput::query()->create([
                    'plan_date' => $date,
                    ...$values,
                    'revision' => 1,
                ]);
            } else {
                $input->fill($values);

                if ($input->isDirty()) {
                    $input->revision++;
                    $input->save();
                }
            }

            if ($manualTaskIds !== null) {
                $manualAssignments = ScheduledTask::query()->where('plan_date', $date)->where('source', 'manual');

                if ($manualTaskIds === []) {
                    $manualAssignments->delete();
                } else {
                    $manualAssignments->whereNotIn('task_id', $manualTaskIds)->delete();
                }

                $scheduledTaskIds = ScheduledTask::query()->where('plan_date', $date)->pluck('task_id')->all();

                foreach (array_diff($manualTaskIds, $scheduledTaskIds) as $taskId) {
                    ScheduledTask::query()->create([
                        'task_id' => $taskId,
                        'plan_date' => $date,
                        'status' => 'planned',
                        'source' => 'manual',
                    ]);
                }
            }

            return $input;
        });

        return $this->resourceWithScheduledTasks($input, $date);
    }

    private function resourceWithScheduledTasks(PlanningDayInput $input, string $date): PlanningDayInputResource
    {
        $scheduledTasks = ScheduledTask::query()
            ->where('plan_date', $date)
            ->with('task:id,title,type,recurrence_type,is_multi_session,lifecycle_state,revision')
            ->orderBy('id')
            ->get();

        return (new PlanningDayInputResource($input))
            ->additional(['scheduled_tasks' => ScheduledTaskResource::collection($scheduledTasks)->resolve()]);
    }

    private function validateDate(string $date): void
    {
        Validator::make(['date' => $date], ['date' => ['required', 'date_format:Y-m-d']])->validate();
    }
}

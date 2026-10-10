<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SavePlanningDayInputRequest;
use App\Http\Resources\PlanningDayInputResource;
use App\Http\Resources\ScheduledTaskResource;
use App\Models\PlanningDayInput;
use App\Models\ScheduledTask;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PlanningDayInputController extends Controller
{
    private const DinnerTasks = [
        ['source' => 'dinner_groceries', 'title' => 'Grocery shopping', 'type' => 'day'],
        ['source' => 'dinner_cooking', 'title' => 'Dinner', 'type' => 'evening'],
    ];

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
        $taskReview = $values['task_review'] ?? null;
        unset($values['manual_task_ids'], $values['task_review']);
        $values['dinner_dish'] = $values['dinner_enabled'] ? $values['dinner_dish'] : null;

        $input = DB::transaction(function () use ($date, $values, $manualTaskIds, $taskReview): PlanningDayInput {
            $completedFinalTaskIds = $this->reviewPendingTasks($date, $taskReview);

            if ($manualTaskIds !== null) {
                $manualTaskIds = array_values(array_diff($manualTaskIds, $completedFinalTaskIds));
            }

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

            $this->syncDinnerTasks($date, $input->dinner_enabled);

            return $input;
        });

        return $this->resourceWithScheduledTasks($input, $date);
    }

    /**
     * @param  array<int, array{scheduled_task_id: int, done: bool}>|null  $taskReview
     * @return array<int, int>
     */
    private function reviewPendingTasks(string $setupDate, ?array $taskReview): array
    {
        $today = CarbonImmutable::now('Europe/Riga');
        $todayDate = $today->toDateString();
        $tomorrowDate = $today->addDay()->toDateString();

        if ($setupDate !== $tomorrowDate) {
            if ($taskReview !== null) {
                throw ValidationException::withMessages(['task_review' => 'Review tasks while setting up tomorrow.']);
            }

            return [];
        }

        $pending = ScheduledTask::query()
            ->where('plan_date', '<=', $todayDate)
            ->where('status', 'planned')
            ->whereHas('task', fn (Builder $query): Builder => $query->where('lifecycle_state', 'active'))
            ->with('task')
            ->orderBy('plan_date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($pending->isEmpty() && $taskReview === null) {
            return [];
        }

        $choices = collect($taskReview ?? [])->keyBy('scheduled_task_id');
        $pendingIds = $pending->pluck('id')->sort()->values()->all();
        $submittedIds = $choices->keys()->map(fn (int|string $id): int => (int) $id)->sort()->values()->all();

        if ($pendingIds !== $submittedIds) {
            throw ValidationException::withMessages(['task_review' => 'Today’s tasks changed. Reload the review and try again.']);
        }

        $reviewedOccurrences = [];
        $completedFinalTaskIds = [];

        foreach ($pending as $assignment) {
            $done = (bool) $choices->get($assignment->id)['done'];
            $occurrenceKey = $assignment->task_id.'|'.$assignment->plan_date;

            if (array_key_exists($occurrenceKey, $reviewedOccurrences)) {
                if ($reviewedOccurrences[$occurrenceKey] !== $done) {
                    throw ValidationException::withMessages(['task_review' => 'Choose the same result for every copy of a task on one day.']);
                }

                continue;
            }

            $reviewedOccurrences[$occurrenceKey] = $done;

            if (! $done) {
                ScheduledTask::query()->where('task_id', $assignment->task_id)
                    ->where('plan_date', $assignment->plan_date)
                    ->where('status', 'planned')
                    ->update(['status' => 'not_completed']);

                continue;
            }

            $task = Task::query()->lockForUpdate()->findOrFail($assignment->task_id);

            if ($task->lifecycle_state !== 'active') {
                throw ValidationException::withMessages(['task_review' => 'A task changed. Reload the review and try again.']);
            }

            $completionType = $task->recurrence_type !== 'one_time'
                ? 'occurrence'
                : ($task->is_multi_session ? 'session' : 'final');

            $task->completions()->create([
                'client_request_id' => (string) Str::uuid(),
                'completion_type' => $completionType,
                'completed_at' => now(),
            ]);

            if ($completionType === 'final') {
                $task->lifecycle_state = 'completed';
                $completedFinalTaskIds[] = $task->id;
            }

            $task->revision++;
            $task->save();

            ScheduledTask::query()->where('task_id', $task->id)
                ->where('plan_date', $assignment->plan_date)
                ->where('status', 'planned')
                ->update(['status' => 'completed']);

            if ($completionType === 'final' && $task->project_id !== null) {
                $project = Task::query()->where('item_type', 'project')
                    ->lockForUpdate()->findOrFail($task->project_id);
                $hasUnfinishedSteps = $project->steps()
                    ->where('lifecycle_state', '!=', 'completed')
                    ->lockForUpdate()->first(['id']) !== null;

                if ($project->lifecycle_state === 'active' && ! $hasUnfinishedSteps) {
                    $project->lifecycle_state = 'completed';
                    $project->revision++;
                    $project->save();
                }
            }
        }

        return $completedFinalTaskIds;
    }

    private function syncDinnerTasks(string $date, bool $dinnerEnabled): void
    {
        foreach (self::DinnerTasks as $definition) {
            $assignment = ScheduledTask::query()
                ->where('plan_date', $date)
                ->where('source', $definition['source'])
                ->first();

            if ($dinnerEnabled) {
                if ($assignment !== null) {
                    continue;
                }

                $task = Task::query()->create([
                    'client_request_id' => (string) Str::uuid(),
                    'title' => $definition['title'],
                    'source' => $definition['source'],
                    'item_type' => 'task',
                    'type' => $definition['type'],
                    'priority' => $definition['type'] === 'evening' ? 'standard' : null,
                    'energy_cost' => $definition['type'] === 'evening' ? 'standard' : null,
                    'recurrence_type' => 'one_time',
                    'lifecycle_state' => 'active',
                    'revision' => 1,
                ]);

                ScheduledTask::query()->create([
                    'task_id' => $task->id,
                    'plan_date' => $date,
                    'status' => 'planned',
                    'source' => $definition['source'],
                ]);

                continue;
            }

            if ($assignment === null) {
                continue;
            }

            $assignment->delete();

            if (! ScheduledTask::query()->where('task_id', $assignment->task_id)->exists()) {
                Task::query()
                    ->whereKey($assignment->task_id)
                    ->where('source', $definition['source'])
                    ->where('lifecycle_state', 'active')
                    ->whereDoesntHave('completions')
                    ->delete();
            }
        }
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

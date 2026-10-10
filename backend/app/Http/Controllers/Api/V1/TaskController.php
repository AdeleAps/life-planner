<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AttachTaskToProjectRequest;
use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\UpdateTaskRequest;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use App\Support\TaskDependencyGraph;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $tasks = Task::query()
            ->where('item_type', 'task')
            ->whereIn('lifecycle_state', $request->boolean('include_completed') ? ['active', 'completed'] : ['active'])
            ->with('dependencies:id,title,lifecycle_state')
            ->latest('id')
            ->get();

        return TaskResource::collection($tasks);
    }

    public function store(StoreTaskRequest $request): JsonResponse
    {
        $input = $request->validated();
        $attributes = $this->taskAttributes($input);

        $task = DB::transaction(function () use ($input, $attributes): Task {
            $task = Task::query()->firstOrCreate(
                ['client_request_id' => $input['client_request_id']],
                [
                    ...$attributes,
                    'source' => 'form',
                    'item_type' => 'task',
                    'lifecycle_state' => 'active',
                    'revision' => 1,
                ],
            );

            abort_if($task->item_type !== 'task', 409, 'Request ID belongs to a project.');

            if ($task->wasRecentlyCreated && ! empty($input['dependency_ids'])) {
                $task->dependencies()->sync($input['dependency_ids']);
            }

            return $task;
        });

        $task->load('dependencies:id,title,lifecycle_state');

        return (new TaskResource($task))
            ->response()
            ->setStatusCode($task->wasRecentlyCreated ? 201 : 200);
    }

    public function update(UpdateTaskRequest $request, Task $task, TaskDependencyGraph $dependencyGraph): JsonResponse
    {
        $input = $request->validated();
        $attributes = $this->taskAttributes($input);
        $dependencyIds = array_map('intval', $input['dependency_ids'] ?? []);

        $task = DB::transaction(function () use ($task, $input, $attributes, $dependencyIds, $dependencyGraph): Task {
            $task = Task::query()
                ->where('item_type', 'task')
                ->where('lifecycle_state', 'active')
                ->lockForUpdate()
                ->findOrFail($task->id);

            abort_if($task->revision !== (int) $input['revision'], 409, 'This task changed since you opened it. Reopen it to edit the latest version.');

            if ($attributes['project_id'] !== null) {
                $project = Task::query()->findOrFail($attributes['project_id']);
                if ($project->dependencies()->whereKey($task->id)->exists()) {
                    throw ValidationException::withMessages(['project_id' => 'This project depends on the task.']);
                }
            }

            $dependencyGraph->assertNoCycle($task->id, $attributes['project_id'], $dependencyIds);
            $task->fill($attributes);
            $task->revision++;
            $task->save();
            $task->dependencies()->sync($dependencyIds);

            return $task;
        });

        $task->load('dependencies:id,title,lifecycle_state');

        return (new TaskResource($task))->response();
    }

    public function attachToProject(AttachTaskToProjectRequest $request, Task $task): JsonResponse
    {
        $projectId = $request->integer('project_id');

        $task = DB::transaction(function () use ($task, $projectId): Task {
            $project = Task::query()
                ->where('item_type', 'project')
                ->where('lifecycle_state', 'active')
                ->findOrFail($projectId);

            $task = Task::query()
                ->where('item_type', 'task')
                ->where('lifecycle_state', 'active')
                ->lockForUpdate()
                ->findOrFail($task->id);

            if ($task->project_id === $project->id) {
                return $task;
            }

            if ($task->recurrence_type !== 'one_time') {
                throw ValidationException::withMessages(['project_id' => 'Recurring tasks cannot be added to projects.']);
            }

            if ($task->project_id !== null) {
                throw ValidationException::withMessages(['project_id' => 'This task is already in a project.']);
            }

            if ($task->dependencies()->whereKey($project->id)->exists() || $project->dependencies()->whereKey($task->id)->exists()) {
                throw ValidationException::withMessages(['project_id' => 'This task and project depend on each other.']);
            }

            $task->project_id = $project->id;
            $task->revision++;
            $task->save();

            return $task;
        });

        $task->load('dependencies:id,title,lifecycle_state');

        return (new TaskResource($task))->response();
    }

    public function removeFromProject(Task $task): JsonResponse
    {
        [$task, $projectEmpty] = DB::transaction(function () use ($task): array {
            $task = Task::query()
                ->where('item_type', 'task')
                ->where('lifecycle_state', 'active')
                ->lockForUpdate()
                ->findOrFail($task->id);

            if ($task->project_id === null) {
                throw ValidationException::withMessages(['project_id' => 'This task is not in a project.']);
            }

            $projectId = $task->project_id;
            $task->project_id = null;
            $task->revision++;
            $task->save();

            $projectEmpty = ! Task::query()->where('project_id', $projectId)->exists();

            return [$task, $projectEmpty];
        });

        $task->load('dependencies:id,title,lifecycle_state');

        return (new TaskResource($task))
            ->additional(['project_empty' => $projectEmpty])
            ->response();
    }

    public function destroy(Task $task): JsonResponse
    {
        [$projectId, $projectEmpty] = DB::transaction(function () use ($task): array {
            $task = Task::query()
                ->where('item_type', 'task')
                ->lockForUpdate()
                ->findOrFail($task->id);

            $projectId = $task->project_id;
            $task->delete();

            $projectEmpty = $projectId !== null
                && ! Task::query()->where('project_id', $projectId)->exists();

            return [$projectId, $projectEmpty];
        });

        return response()->json(['project_id' => $projectId, 'project_empty' => $projectEmpty]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function taskAttributes(array $input): array
    {
        $taskType = $input['type'] ?? 'evening';
        $recurrenceType = $input['recurrence_type'] ?? 'one_time';
        $isOneTimeEvening = $taskType === 'evening' && $recurrenceType === 'one_time';
        $recurrenceRule = null;
        $deadlineOn = $isOneTimeEvening ? ($input['deadline_on'] ?? null) : null;
        $dayPreference = $taskType === 'evening' ? ($input['day_preference'] ?? null) : null;

        if ($recurrenceType === 'schedule') {
            $rule = $input['recurrence_rule'];
            $recurrenceRule = [
                'unit' => $rule['unit'],
                'interval' => (int) $rule['interval'],
            ];

            if ($rule['unit'] === 'week') {
                $recurrenceRule['weekdays'] = array_map('intval', $rule['weekdays']);
            } elseif ($rule['unit'] === 'month') {
                $recurrenceRule['day_of_month'] = (int) $rule['day_of_month'];
            }
        } elseif ($recurrenceType === 'count') {
            $rule = $input['recurrence_rule'];
            $recurrenceRule = [
                'period_unit' => $rule['period_unit'],
                'period_interval' => (int) $rule['period_interval'],
                'min_occurrences' => (int) $rule['min_occurrences'],
                'max_occurrences' => (int) $rule['max_occurrences'],
            ];
        }

        return [
            'title' => $input['title'],
            'description' => $input['description'] ?? null,
            'project_id' => $input['project_id'] ?? null,
            'type' => $taskType,
            'priority' => $taskType === 'day' ? null : ($input['priority'] ?? 'standard'),
            'energy_cost' => $taskType === 'day' ? null : ($input['energy_cost'] ?? 'standard'),
            'recurrence_type' => $recurrenceType,
            'recurrence_rule' => $recurrenceRule,
            'is_multi_session' => $isOneTimeEvening ? ($input['is_multi_session'] ?? false) : false,
            'advance_notice_days' => $taskType === 'evening' ? ($input['advance_notice_days'] ?? null) : null,
            'day_preference' => $dayPreference,
            'day_preference_is_hard' => $dayPreference === null ? null : ($input['day_preference_is_hard'] ?? false),
            'deadline_on' => $deadlineOn,
            'deadline_is_hard' => $deadlineOn === null ? null : ($input['deadline_is_hard'] ?? false),
        ];
    }
}

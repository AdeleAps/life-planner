<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AttachTaskToProjectRequest;
use App\Http\Requests\StoreTaskRequest;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $tasks = Task::query()
            ->where('item_type', 'task')
            ->where('lifecycle_state', 'active')
            ->with('dependencies:id,title,lifecycle_state')
            ->latest('id')
            ->get();

        return TaskResource::collection($tasks);
    }

    public function store(StoreTaskRequest $request): JsonResponse
    {
        $input = $request->validated();
        $taskType = $input['type'] ?? 'evening';
        $recurrenceType = $input['recurrence_type'] ?? 'one_time';
        $isOneTimeEvening = $taskType === 'evening' && $recurrenceType === 'one_time';
        $recurrenceRule = null;
        $deadlineOn = $isOneTimeEvening
            ? ($input['deadline_on'] ?? null)
            : null;

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

        $task = DB::transaction(function () use ($input, $taskType, $recurrenceType, $recurrenceRule, $isOneTimeEvening, $deadlineOn): Task {
            $task = Task::query()->firstOrCreate(
                ['client_request_id' => $input['client_request_id']],
                [
                    'title' => $input['title'],
                    'description' => $input['description'] ?? null,
                    'source' => 'form',
                    'item_type' => 'task',
                    'project_id' => $input['project_id'] ?? null,
                    'type' => $taskType,
                    'priority' => $taskType === 'day' ? null : ($input['priority'] ?? 'standard'),
                    'energy_cost' => $taskType === 'day' ? null : ($input['energy_cost'] ?? 'standard'),
                    'recurrence_type' => $recurrenceType,
                    'recurrence_rule' => $recurrenceRule,
                    'is_multi_session' => $isOneTimeEvening ? ($input['is_multi_session'] ?? false) : false,
                    'deadline_on' => $deadlineOn,
                    'deadline_is_hard' => $deadlineOn === null ? null : ($input['deadline_is_hard'] ?? false),
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
}

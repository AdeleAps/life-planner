<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTaskRequest;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TaskController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $tasks = Task::query()
            ->where('lifecycle_state', 'active')
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

        $task = Task::query()->firstOrCreate(
            ['client_request_id' => $input['client_request_id']],
            [
                'title' => $input['title'],
                'description' => $input['description'] ?? null,
                'source' => 'form',
                'kind' => $recurrenceType === 'one_time' ? 'one_off' : 'recurring',
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

        return (new TaskResource($task))
            ->response()
            ->setStatusCode($task->wasRecentlyCreated ? 201 : 200);
    }
}

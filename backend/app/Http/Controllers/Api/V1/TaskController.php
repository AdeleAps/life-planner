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

        $task = Task::query()->firstOrCreate(
            ['client_request_id' => $input['client_request_id']],
            [
                'title' => $input['title'],
                'description' => $input['description'] ?? null,
                'source' => 'form',
                'kind' => 'one_off',
                'type' => $input['type'] ?? 'evening',
                'priority' => 'standard',
                'energy_cost' => $input['energy_cost'],
                'lifecycle_state' => 'active',
                'revision' => 1,
            ],
        );

        return (new TaskResource($task))
            ->response()
            ->setStatusCode($task->wasRecentlyCreated ? 201 : 200);
    }
}

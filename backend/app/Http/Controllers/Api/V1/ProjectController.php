<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class ProjectController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $projects = Task::query()
            ->where('item_type', 'project')
            ->where('lifecycle_state', 'active')
            ->with('dependencies:id,title,lifecycle_state')
            ->latest('id')
            ->get();

        return TaskResource::collection($projects);
    }

    public function store(StoreProjectRequest $request): JsonResponse
    {
        $input = $request->validated();
        $deadlineOn = $input['deadline_on'] ?? null;

        $project = DB::transaction(function () use ($input, $deadlineOn): Task {
            $project = Task::query()->firstOrCreate(
                ['client_request_id' => $input['client_request_id']],
                [
                    'title' => $input['title'],
                    'description' => $input['description'] ?? null,
                    'source' => 'form',
                    'item_type' => 'project',
                    'project_id' => null,
                    'type' => null,
                    'priority' => $input['priority'] ?? 'standard',
                    'energy_cost' => null,
                    'recurrence_type' => 'one_time',
                    'recurrence_rule' => null,
                    'is_multi_session' => false,
                    'deadline_on' => $deadlineOn,
                    'deadline_is_hard' => $deadlineOn === null ? null : ($input['deadline_is_hard'] ?? false),
                    'lifecycle_state' => 'active',
                    'revision' => 1,
                ],
            );

            abort_if($project->item_type !== 'project', 409, 'Request ID belongs to a task.');

            if ($project->wasRecentlyCreated && ! empty($input['dependency_ids'])) {
                $project->dependencies()->sync($input['dependency_ids']);
            }

            return $project;
        });

        $project->load('dependencies:id,title,lifecycle_state');

        return (new TaskResource($project))
            ->response()
            ->setStatusCode($project->wasRecentlyCreated ? 201 : 200);
    }
}

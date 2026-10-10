<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use App\Support\TaskDependencyGraph;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProjectController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $projects = Task::query()
            ->where('item_type', 'project')
            ->where('lifecycle_state', 'active')
            ->with('dependencies:id,title,lifecycle_state')
            ->withCount('steps')
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

        $project->load('dependencies:id,title,lifecycle_state')->loadCount('steps');

        return (new TaskResource($project))
            ->response()
            ->setStatusCode($project->wasRecentlyCreated ? 201 : 200);
    }

    public function update(UpdateProjectRequest $request, Task $project, TaskDependencyGraph $dependencyGraph): JsonResponse
    {
        $input = $request->validated();
        $dependencyIds = array_map('intval', $input['dependency_ids'] ?? []);

        $project = DB::transaction(function () use ($project, $input, $dependencyIds, $dependencyGraph): Task {
            $project = Task::query()
                ->where('item_type', 'project')
                ->where('lifecycle_state', 'active')
                ->lockForUpdate()
                ->findOrFail($project->id);

            abort_if($project->revision !== (int) $input['revision'], 409, 'This project changed since you opened it. Reopen it to edit the latest version.');

            if (Task::query()->where('project_id', $project->id)->whereKey($dependencyIds)->exists()) {
                throw ValidationException::withMessages(['dependency_ids' => 'A project cannot depend on its own tasks.']);
            }

            $dependencyGraph->assertNoCycle($project->id, null, $dependencyIds);

            $deadlineOn = $input['deadline_on'] ?? null;
            $project->fill([
                'title' => $input['title'],
                'description' => $input['description'] ?? null,
                'priority' => $input['priority'] ?? 'standard',
                'deadline_on' => $deadlineOn,
                'deadline_is_hard' => $deadlineOn === null ? null : ($input['deadline_is_hard'] ?? false),
            ]);
            $project->revision++;
            $project->save();
            $project->dependencies()->sync($dependencyIds);

            return $project;
        });

        $project->load('dependencies:id,title,lifecycle_state')->loadCount('steps');

        return (new TaskResource($project))->response();
    }

    public function destroy(Request $request, Task $project): JsonResponse
    {
        $input = $request->validate([
            'task_action' => ['required', Rule::in(['detach', 'delete'])],
        ]);

        $affectedTasks = DB::transaction(function () use ($project, $input): int {
            $project = Task::query()
                ->where('item_type', 'project')
                ->where('lifecycle_state', 'active')
                ->lockForUpdate()
                ->findOrFail($project->id);

            if ($input['task_action'] === 'detach') {
                $affectedTasks = $project->steps()->update([
                    'project_id' => null,
                    'revision' => DB::raw('revision + 1'),
                ]);
            } else {
                $affectedTasks = $project->steps()->delete();
            }

            $project->delete();

            return $affectedTasks;
        });

        return response()->json([
            'task_action' => $input['task_action'],
            'affected_tasks' => $affectedTasks,
        ]);
    }
}

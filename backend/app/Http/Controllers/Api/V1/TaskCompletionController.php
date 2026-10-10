<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTaskCompletionRequest;
use App\Http\Resources\TaskCompletionResource;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use App\Models\TaskCompletion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaskCompletionController extends Controller
{
    public function index(Task $task): AnonymousResourceCollection
    {
        abort_unless($task->item_type === 'task', 404);

        $completions = $task->completions()
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->get();

        return TaskCompletionResource::collection($completions)
            ->additional(['total' => $completions->count()]);
    }

    public function store(StoreTaskCompletionRequest $request, Task $task): JsonResponse
    {
        $input = $request->validated();

        [$task, $completion, $created] = DB::transaction(function () use ($task, $input): array {
            $task = Task::query()
                ->where('item_type', 'task')
                ->lockForUpdate()
                ->findOrFail($task->id);

            $existing = TaskCompletion::query()
                ->where('client_request_id', $input['client_request_id'])
                ->first();

            if ($existing !== null) {
                abort_if($existing->task_id !== $task->id || $existing->completion_type !== $input['completion_type'], 409, 'Request ID belongs to another completion.');

                return [$task, $existing, false];
            }

            abort_if($task->lifecycle_state !== 'active', 409, 'This task is already completed.');
            abort_if($task->revision !== (int) $input['revision'], 409, 'This task changed since you opened it. Reopen it to get the latest version.');

            $allowedTypes = $task->recurrence_type !== 'one_time'
                ? ['occurrence']
                : ($task->is_multi_session ? ['session', 'final'] : ['final']);

            if (! in_array($input['completion_type'], $allowedTypes, true)) {
                throw ValidationException::withMessages(['completion_type' => 'Choose a valid completion action for this task.']);
            }

            $completion = $task->completions()->create([
                'client_request_id' => $input['client_request_id'],
                'completion_type' => $input['completion_type'],
                'completed_at' => now(),
            ]);

            if ($input['completion_type'] === 'final') {
                $task->lifecycle_state = 'completed';
            }

            $task->revision++;
            $task->save();

            return [$task, $completion, true];
        });

        $task->load('dependencies:id,title,lifecycle_state');

        return response()->json([
            'data' => (new TaskResource($task))->toArray($request),
            'completion' => (new TaskCompletionResource($completion))->toArray($request),
            'completion_count' => $task->completions()->count(),
        ], $created ? 201 : 200);
    }
}

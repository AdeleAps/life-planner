<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ScheduledTaskResource;
use App\Models\PlanningDayInput;
use App\Models\ScheduledTask;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DevResetController extends Controller
{
    public function __invoke(): JsonResponse
    {
        abort_unless(app()->environment(['local', 'testing']), 404);

        $today = CarbonImmutable::now('Europe/Riga');
        $todayDate = $today->toDateString();
        $tomorrowDate = $today->addDay()->toDateString();

        $scheduledTasks = DB::transaction(function () use ($todayDate, $tomorrowDate) {
            $dinnerTaskIds = ScheduledTask::query()
                ->where('plan_date', $tomorrowDate)
                ->whereIn('source', ['dinner_groceries', 'dinner_cooking'])
                ->pluck('task_id');

            PlanningDayInput::query()->where('plan_date', $tomorrowDate)->delete();
            ScheduledTask::query()->whereIn('plan_date', [$todayDate, $tomorrowDate])->delete();

            foreach ($dinnerTaskIds as $dinnerTaskId) {
                if (! ScheduledTask::query()->where('task_id', $dinnerTaskId)->exists()) {
                    Task::query()
                        ->whereKey($dinnerTaskId)
                        ->whereIn('source', ['dinner_groceries', 'dinner_cooking'])
                        ->where('lifecycle_state', 'active')
                        ->whereDoesntHave('completions')
                        ->delete();
                }
            }

            Task::query()->where('source', 'test_reset')->delete();

            foreach ([
                ['title' => 'Water the plants', 'type' => 'day'],
                ['title' => 'Tidy the kitchen', 'type' => 'evening'],
                ['title' => 'Read a chapter', 'type' => 'evening'],
            ] as $sample) {
                $task = Task::query()->create([
                    'client_request_id' => (string) Str::uuid(),
                    'title' => $sample['title'],
                    'source' => 'test_reset',
                    'item_type' => 'task',
                    'type' => $sample['type'],
                    'priority' => $sample['type'] === 'evening' ? 'standard' : null,
                    'energy_cost' => $sample['type'] === 'evening' ? 'standard' : null,
                    'recurrence_type' => 'one_time',
                    'lifecycle_state' => 'active',
                    'revision' => 1,
                ]);

                ScheduledTask::query()->create([
                    'task_id' => $task->id,
                    'plan_date' => $todayDate,
                    'status' => 'planned',
                    'source' => 'test_reset',
                ]);
            }

            return ScheduledTask::query()
                ->where('plan_date', $todayDate)
                ->with('task:id,title,type,recurrence_type,is_multi_session,lifecycle_state,revision')
                ->orderBy('id')
                ->get();
        });

        return response()->json([
            'today' => $todayDate,
            'tomorrow' => $tomorrowDate,
            'scheduled_tasks' => ScheduledTaskResource::collection($scheduledTasks)->resolve(),
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PlanningCycle;
use App\Models\PlanningDayInput;
use App\Models\ScheduledTask;
use App\Services\PlanningBriefBuilder;
use App\Services\PlanningCapacityPolicy;
use App\Services\TaskResearcher;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PlanningPlanController extends Controller
{
    public function show(string $date, PlanningBriefBuilder $briefBuilder, PlanningCapacityPolicy $capacityPolicy): JsonResponse
    {
        $this->validateDate($date);
        $input = PlanningDayInput::query()->where('plan_date', $date)->first();
        $brief = $briefBuilder->build(CarbonImmutable::parse($date, 'Europe/Riga'));
        $deadlinePressureWarnings = $capacityPolicy->deadlinePressureWarnings($brief);

        if ($input === null) {
            return response()->json(['data' => ['draft' => null, 'published' => false, 'note' => '', 'deadline_pressure_warnings' => $deadlinePressureWarnings]]);
        }

        $existingTaskIds = $this->existingTaskIds($date);
        $latest = DB::table('ai_planning_requests')->where('plan_date', $date)
            ->where('status', 'completed')->orderByDesc('id')->limit(3)->get()
            ->map(fn (object $request): array => json_decode($request->draft, true, 512, JSON_THROW_ON_ERROR))
            ->first(fn (array $draft): bool => ($draft['setup_id'] ?? null) === $input->id
                && ($draft['setup_revision'] ?? null) === $input->revision
                && ($draft['existing_task_ids'] ?? null) === $existingTaskIds
                && (($draft['published'] ?? false) || ($draft['brief_fingerprint'] ?? null) === $briefBuilder->fingerprint($brief)));
        $cycleComplete = PlanningCycle::query()->where('plan_date', $date)->whereNotNull('plan_completed_at')->exists();
        $published = $cycleComplete && ($input->day_off || ($latest['published'] ?? false));

        return response()->json(['data' => [
            'draft' => $latest,
            'published' => $published,
            'note' => $published ? ($latest['note'] ?? '') : '',
            'deadline_pressure_warnings' => $deadlinePressureWarnings,
        ]]);
    }

    public function store(Request $request, string $date, PlanningBriefBuilder $briefBuilder, PlanningCapacityPolicy $capacityPolicy, TaskResearcher $taskResearcher): JsonResponse
    {
        $this->validateDate($date);

        if ($date !== CarbonImmutable::now('Europe/Riga')->addDay()->toDateString()) {
            throw ValidationException::withMessages(['date' => 'Only tomorrow’s plan can be saved here.']);
        }

        $values = $request->validate([
            'client_request_id' => ['nullable', 'uuid'],
            'task_ids' => ['present', 'array', 'max:20'],
            'task_ids.*' => ['required', 'integer', 'distinct'],
        ]);

        DB::transaction(function () use ($date, $values, $briefBuilder, $capacityPolicy, $taskResearcher): void {
            $input = PlanningDayInput::query()->where('plan_date', $date)->lockForUpdate()->first();

            if ($input === null) {
                throw ValidationException::withMessages(['date' => 'Save tomorrow’s setup first.']);
            }

            if ($input->day_off) {
                if ($values['task_ids'] !== []) {
                    throw ValidationException::withMessages(['task_ids' => 'A Day Off only includes tasks you added yourself.']);
                }

                ScheduledTask::query()->where('plan_date', $date)->where('source', 'planner')->delete();
                $this->markComplete($date);

                return;
            }

            $requestId = $values['client_request_id'] ?? null;

            if ($requestId === null) {
                throw ValidationException::withMessages(['client_request_id' => 'Get a draft before saving the plan.']);
            }

            $record = DB::table('ai_planning_requests')->where('client_request_id', $requestId)
                ->where('plan_date', $date)->where('status', 'completed')->lockForUpdate()->first();
            $latestId = DB::table('ai_planning_requests')->where('plan_date', $date)
                ->where('status', 'completed')->max('id');

            if ($record === null || (int) $record->id !== (int) $latestId) {
                throw ValidationException::withMessages(['client_request_id' => 'This draft is no longer current.']);
            }

            $draft = json_decode($record->draft, true, 512, JSON_THROW_ON_ERROR);
            $existingTaskIds = $this->existingTaskIds($date);
            $currentBrief = $briefBuilder->build(CarbonImmutable::parse($date, 'Europe/Riga'));

            if (($draft['setup_id'] ?? null) !== $input->id
                || ($draft['setup_revision'] ?? null) !== $input->revision
                || ($draft['existing_task_ids'] ?? null) !== $existingTaskIds
                || ($draft['brief_fingerprint'] ?? null) !== $briefBuilder->fingerprint($currentBrief)) {
                throw ValidationException::withMessages(['client_request_id' => 'Tomorrow changed. Get a fresh draft.']);
            }

            $selectedIds = $values['task_ids'];

            if (array_diff($selectedIds, $draft['task_ids']) !== []) {
                throw ValidationException::withMessages(['task_ids' => 'Choose tasks from this draft.']);
            }

            if (array_intersect($selectedIds, $draft['needs_checks_task_ids']) !== []) {
                throw ValidationException::withMessages(['task_ids' => 'Tasks that need research cannot be saved yet.']);
            }

            ScheduledTask::query()->where('plan_date', $date)->where('source', 'planner')->delete();
            $brief = $briefBuilder->build(CarbonImmutable::parse($date, 'Europe/Riga'));
            $candidates = collect($brief['standing_tasks'])->keyBy('id');

            foreach ($selectedIds as $taskId) {
                $candidate = $candidates[$taskId] ?? null;
                $state = $candidate['target_assessment']['state'] ?? null;
                $researched = in_array($taskId, $draft['researched_eligible_task_ids'] ?? [], true)
                    && ($draft['research_results'][$taskId]['status'] ?? null) === 'ready'
                    && $candidate !== null && $taskResearcher->canResearch($candidate);

                if ($state !== 'preliminary_candidate' && ! ($state === 'needs_checks' && $researched)) {
                    throw ValidationException::withMessages(['task_ids' => 'A task changed. Get a fresh draft.']);
                }
            }

            $capacity = $capacityPolicy->assess($brief, $selectedIds);

            if ($capacity['errors'] !== []) {
                throw ValidationException::withMessages(['task_ids' => $capacity['errors'][0]]);
            }

            foreach ($selectedIds as $taskId) {
                ScheduledTask::query()->create([
                    'task_id' => $taskId,
                    'plan_date' => $date,
                    'status' => 'planned',
                    'source' => 'planner',
                ]);
            }

            $draft['published'] = true;
            $draft['published_task_ids'] = $selectedIds;
            $draft['capacity_warnings'] = $capacity['warnings'];
            DB::table('ai_planning_requests')->where('id', $record->id)->update([
                'draft' => json_encode($draft, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
            $this->markComplete($date);
        }, 3);

        return $this->show($date, $briefBuilder, $capacityPolicy);
    }

    private function validateDate(string $date): void
    {
        Validator::make(['date' => $date], ['date' => ['required', 'date_format:Y-m-d']])->validate();
    }

    /** @return array<int, int> */
    private function existingTaskIds(string $date): array
    {
        return ScheduledTask::query()->where('plan_date', $date)->where('source', '!=', 'planner')
            ->orderBy('task_id')->pluck('task_id')->map(fn (int|string $id): int => (int) $id)->all();
    }

    private function markComplete(string $date): void
    {
        PlanningCycle::query()->updateOrCreate(['plan_date' => $date], ['plan_completed_at' => now()]);
    }
}

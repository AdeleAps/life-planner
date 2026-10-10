<?php

namespace App\Services;

use App\Models\PlanningDayInput;
use App\Models\PlanningFocus;
use App\Models\ScheduledTask;
use App\Models\Task;
use App\Models\TaskCompletion;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class PlanningBriefBuilder
{
    public function __construct(private DayOffCalendar $dayOffCalendar) {}

    /** @param  array<string, mixed>  $brief */
    public function fingerprint(array $brief): string
    {
        unset($brief['built_at']);

        return hash('sha256', json_encode($brief, JSON_THROW_ON_ERROR));
    }

    /**
     * A read-only snapshot for a planning run, never a published plan.
     *
     * @return array<string, mixed>
     */
    public function build(CarbonImmutable $targetDate): array
    {
        $targetDate = $targetDate->startOfDay();
        $lastDate = $targetDate->addDays(13);
        $today = CarbonImmutable::now('Europe/Riga')->startOfDay();
        $calendarDays = $this->dayOffCalendar->days($targetDate, $lastDate);
        $focuses = PlanningFocus::query()
            ->whereNotNull('focus')
            ->where('starts_on', '>=', $targetDate->subMonth()->startOfMonth()->toDateString())
            ->where('starts_on', '<=', $lastDate->toDateString())
            ->orderBy('starts_on')->get()
            ->filter(fn (PlanningFocus $focus): bool => $focus->periodEnd()->greaterThanOrEqualTo($targetDate));

        $inputs = PlanningDayInput::query()
            ->whereBetween('plan_date', [$targetDate->toDateString(), $lastDate->toDateString()])
            ->get()->keyBy('plan_date');

        $assignments = ScheduledTask::query()
            ->where('status', 'planned')
            ->where('plan_date', '>=', $targetDate->toDateString())
            ->with('task:id,title,type,energy_cost,lifecycle_state')
            ->orderBy('plan_date')->orderBy('id')->get();
        $assignmentsByDate = $assignments->groupBy('plan_date');
        $assignmentsByTask = $assignments->groupBy('task_id');

        $tasks = Task::query()
            ->where('item_type', 'task')->where('lifecycle_state', 'active')
            ->with('dependencies:id,title,lifecycle_state', 'project:id,title,priority,deadline_on,deadline_is_hard,lifecycle_state')
            ->withCount('completions')->withMax('completions', 'completed_at')
            ->withMax(['completions as last_session_completed_at' => fn (Builder $query): Builder => $query->where('completion_type', 'session')], 'completed_at')
            ->orderBy('id')->get();

        $reviewedAssignments = ScheduledTask::query()
            ->whereIn('task_id', $tasks->pluck('id'))
            ->whereIn('status', ['completed', 'not_completed'])
            ->where('plan_date', '<', $targetDate->toDateString())
            ->orderBy('plan_date')->get()->groupBy('task_id');

        $multiSessionTaskIds = $tasks->filter(fn (Task $task): bool => $task->is_multi_session)->pluck('id');
        $recentStart = $targetDate->subDays(14)->toDateString();
        $recentScheduledDays = ScheduledTask::query()
            ->whereIn('task_id', $multiSessionTaskIds)
            ->where('plan_date', '>=', $recentStart)
            ->where('plan_date', '<', $targetDate->toDateString())
            ->orderBy('plan_date')->get()->groupBy('task_id');
        $recentSessionCompletions = TaskCompletion::query()
            ->whereIn('task_id', $multiSessionTaskIds)
            ->where('completion_type', 'session')
            ->where('completed_at', '>=', $targetDate->subDays(14)->setTimezone('UTC')->toDateTimeString())
            ->where('completed_at', '<', $targetDate->setTimezone('UTC')->toDateTimeString())
            ->orderBy('completed_at')->get()->groupBy('task_id');

        $projects = Task::query()
            ->where('item_type', 'project')->where('lifecycle_state', 'active')
            ->with('dependencies:id,title,lifecycle_state')
            ->withCount(['steps as remaining_step_count' => fn ($query) => $query->where('lifecycle_state', 'active')])
            ->orderBy('id')->get();

        $targetKey = $targetDate->toDateString();
        $targetInput = $inputs->get($targetKey);
        $targetHighEnergyEveningCount = $assignmentsByDate->get($targetKey, collect())
            ->filter(fn (ScheduledTask $assignment): bool => $assignment->task?->type === 'evening'
                && $assignment->task->energy_cost === 'high')->count();

        return [
            'target_date' => $targetKey,
            'timezone' => 'Europe/Riga',
            'built_at' => CarbonImmutable::now('Europe/Riga')->toIso8601String(),
            'horizon_end_date' => $lastDate->toDateString(),
            'snapshot_limits' => [
                'calendar' => 'not_connected',
                'national_holidays' => $calendarDays->every(fn (array $day): bool => $day['holiday_year_synced']) ? 'synced' : 'not_fully_synced',
                'advance_acceptances' => 'not_implemented',
                'recurrence_due_dates' => 'not_evaluated',
                'future_energy' => 'unknown_unless_saved',
                'capacity' => 'known_task_counts_only',
                'session_history' => 'completion_timestamps_can_reflect_late_review; scheduled_dates_are_listed_separately',
            ],
            'recent_activity_lookback_days' => 14,
            'planning_guidance' => [
                'Recently started multi-session tasks should be softly prioritized so started work moves toward completion.',
                'An unfinished Day task is normally carried to the next day automatically. Unfinished Evening tasks should get a quiet priority to return soon, preferably after at least one day, unless a deadline or required recurrence makes tomorrow necessary.',
                'Normally plan no more than three Day tasks on any date, regardless of workday or weekend. Exceed three only when genuine deadline pressure requires it.',
                'Avoid scheduling the same task on more than two consecutive days unless genuinely necessary, such as deadline pressure.',
                'Prefer one Evening task on workdays. On a weekend or holiday, one or two are both normal; do not add a second just to fill capacity. Prefer one on a low-energy day, including a day off. A workday needs a real deadline or unavoidable required recurrence to justify two. Three on a weekend or holiday are only for dire deadline urgency. The explicit Day Off setting still prevents automatic additions.',
                'Normally avoid two High-energy Evening tasks on the same day; add a second only when genuine deadline pressure requires it.',
                'Goon mode is a hard pause: plan no tasks on those dates, regardless of deadlines or ordinary Day Off capacity.',
                'Weekly and monthly focuses provide context for future optional suggestions. Do not use them to rank existing tasks for tomorrow.',
            ],
            'focuses' => $focuses->map(fn (PlanningFocus $focus): array => [
                'kind' => $focus->kind,
                'starts_on' => $focus->starts_on,
                'ends_on' => $focus->periodEnd()->toDateString(),
                'focus' => $focus->focus,
            ])->values()->all(),
            'setup' => $this->setup($targetInput),
            'pending_review_count' => ScheduledTask::query()
                ->where('status', 'planned')->where('plan_date', '<', $targetKey)
                ->whereHas('task', fn (Builder $query): Builder => $query->where('lifecycle_state', 'active'))
                ->count(),
            'projects' => $projects->map(fn (Task $project): array => [
                'id' => $project->id,
                'title' => $project->title,
                'description' => $project->description,
                'priority' => $project->priority,
                'deadline_on' => $project->deadline_on?->toDateString(),
                'days_until_deadline' => $this->daysUntilDeadline($project->deadline_on, $targetDate),
                'deadline_is_hard' => $project->deadline_is_hard,
                'remaining_step_count' => $project->remaining_step_count,
                'blocking_dependencies' => $project->dependencies
                    ->filter(fn (Task $dependency): bool => $dependency->lifecycle_state !== 'completed')
                    ->map(fn (Task $dependency): array => ['id' => $dependency->id, 'title' => $dependency->title])
                    ->values()->all(),
            ])->all(),
            'standing_tasks' => $tasks->map(fn (Task $task): array => $this->taskEntry(
                $task, $targetDate, $today, $targetInput, $assignmentsByTask->get($task->id, collect()),
                $recentScheduledDays->get($task->id, collect()), $recentSessionCompletions->get($task->id, collect()),
                $targetHighEnergyEveningCount, $calendarDays->get($targetKey), $reviewedAssignments->get($task->id, collect()),
            ))->all(),
            'capacity_days' => collect(range(0, 13))->map(function (int $offset) use ($targetDate, $inputs, $assignmentsByDate, $tasks, $projects, $calendarDays): array {
                $date = $targetDate->addDays($offset);
                $key = $date->toDateString();
                $input = $inputs->get($key);
                $calendarDay = $calendarDays->get($key);
                $dayAssignments = $assignmentsByDate->get($key, collect());
                $dayCount = $dayAssignments->filter(fn (ScheduledTask $assignment): bool => $assignment->task?->type === 'day')->count();
                $eveningCount = $dayAssignments->filter(fn (ScheduledTask $assignment): bool => $assignment->task?->type === 'evening')->count();
                $highEnergyEveningCount = $dayAssignments->filter(fn (ScheduledTask $assignment): bool => $assignment->task?->type === 'evening'
                    && $assignment->task->energy_cost === 'high')->count();
                $ordinaryEveningLimit = $calendarDay['goon_mode'] ? 0 : ($calendarDay['is_day_off'] ? 2 : 1);
                $emergencyEveningLimit = $calendarDay['goon_mode'] ? 0 : ($calendarDay['is_day_off'] ? 3 : 2);
                $comfortableEveningCounts = $calendarDay['goon_mode'] ? [0]
                    : ($calendarDay['is_day_off'] && $input?->energy_level !== 'low' ? [1, 2] : [1]);

                return [
                    'date' => $key,
                    'calendar_weekday' => $date->format('l'),
                    'weekend_by_calendar' => $date->isWeekend(),
                    'workday_classification' => $calendarDay['day_type'],
                    'goon_mode' => $calendarDay['goon_mode'],
                    'holiday_names' => $calendarDay['holiday_names'],
                    'personal_days_off' => $calendarDay['personal_days_off'],
                    'setup' => $this->setup($input),
                    'known_day_task_count' => $dayCount,
                    'ordinary_day_task_limit' => 3,
                    'known_room_to_ordinary_day_limit' => max(0, 3 - $dayCount),
                    'known_evening_task_count' => $eveningCount,
                    'known_high_energy_evening_task_count' => $highEnergyEveningCount,
                    'comfortable_evening_task_counts' => $comfortableEveningCounts,
                    'ordinary_evening_task_limit' => $ordinaryEveningLimit,
                    'absolute_evening_task_limit' => $emergencyEveningLimit,
                    'limit_day_type_basis' => $calendarDay['day_type'] === 'unverified' ? 'provisional_weekday' : 'confirmed_calendar',
                    'known_room_to_comfortable_limit' => max(0, max($comfortableEveningCounts) - $eveningCount),
                    'known_room_to_ordinary_limit' => max(0, $ordinaryEveningLimit - $eveningCount),
                    'known_room_to_absolute_limit' => max(0, $emergencyEveningLimit - $eveningCount),
                    'preferred_high_energy_evening_task_limit' => $calendarDay['goon_mode'] ? 0 : 1,
                    'known_room_to_preferred_high_energy_limit' => max(0, ($calendarDay['goon_mode'] ? 0 : 1) - $highEnergyEveningCount),
                    'automatic_additions' => $calendarDay['goon_mode'] ? 'none_goon_mode'
                        : ($input?->day_off ? 'none_day_off' : ($input?->dinner_enabled ? 'no_independent_evening_task' : 'subject_to_checks')),
                    'known_assignments' => $dayAssignments->map(fn (ScheduledTask $assignment): array => [
                        'task_id' => $assignment->task_id,
                        'title' => $assignment->task?->title,
                        'type' => $assignment->task?->type,
                        'source' => $assignment->source,
                    ])->all(),
                    'deadlines_on_date' => $tasks->concat($projects)
                        ->filter(fn (Task $item): bool => $item->deadline_on?->toDateString() === $key)
                        ->map(fn (Task $item): array => [
                            'id' => $item->id,
                            'item_type' => $item->item_type,
                            'title' => $item->title,
                            'hard' => (bool) $item->deadline_is_hard,
                        ])->values()->all(),
                ];
            })->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function setup(?PlanningDayInput $input): array
    {
        return [
            'saved' => $input !== null,
            'revision' => $input?->revision,
            'energy_level' => $input?->energy_level,
            'day_off' => $input?->day_off,
            'dinner_enabled' => $input?->dinner_enabled,
            'dinner_dish' => $input?->dinner_dish,
        ];
    }

    /**
     * @param  Collection<int, ScheduledTask>  $assignments
     * @param  Collection<int, ScheduledTask>  $recentScheduledDays
     * @param  Collection<int, TaskCompletion>  $recentSessionCompletions
     * @param  Collection<int, ScheduledTask>  $reviewedAssignments
     * @return array<string, mixed>
     */
    private function taskEntry(
        Task $task,
        CarbonImmutable $targetDate,
        CarbonImmutable $today,
        ?PlanningDayInput $input,
        Collection $assignments,
        Collection $recentScheduledDays,
        Collection $recentSessionCompletions,
        int $targetHighEnergyEveningCount,
        array $calendarDay,
        Collection $reviewedAssignments,
    ): array {
        $targetKey = $targetDate->toDateString();
        $assignedOnTarget = $assignments->contains(fn (ScheduledTask $assignment): bool => $assignment->plan_date === $targetKey);
        $blockingDependencies = $task->dependencies->filter(fn (Task $dependency): bool => $dependency->lifecycle_state !== 'completed');
        $blocks = [];
        $checks = [];
        $softFactors = [];
        $lastCompletedDate = $reviewedAssignments->where('status', 'completed')->max('plan_date');
        $unfinishedAssignments = $reviewedAssignments->where('status', 'not_completed')
            ->filter(fn (ScheduledTask $assignment): bool => $lastCompletedDate === null || $assignment->plan_date > $lastCompletedDate);
        $lastUnfinishedDate = $unfinishedAssignments->max('plan_date');
        $notBeforeDate = $unfinishedAssignments->pluck('not_before_on')->filter()->max();

        if ($notBeforeDate !== null && $targetKey < $notBeforeDate && ! $assignedOnTarget) {
            $blocks[] = 'deferred_until';
        }

        if ($lastUnfinishedDate !== null && ! $assignedOnTarget) {
            $softFactors[] = 'unfinished_task_priority';

            if ($task->type === 'evening' && $lastUnfinishedDate === $targetDate->subDay()->toDateString()) {
                $softFactors[] = 'prefer_gap_after_unfinished_evening';
            }
        }

        if ($blockingDependencies->isNotEmpty()) {
            $blocks[] = 'dependencies_incomplete';
        }

        if ($calendarDay['goon_mode']) {
            $blocks[] = 'goon_mode';
        }

        if ($input?->day_off && ! $assignedOnTarget) {
            $blocks[] = 'day_off_manual_only';
        }

        if ($input?->dinner_enabled && $task->type === 'evening' && ! $assignedOnTarget) {
            $blocks[] = 'dinner_uses_evening_capacity';
        }

        if ($task->recurrence_type === 'one_time' && ! $task->is_multi_session && ! $assignedOnTarget && $assignments->isNotEmpty()) {
            $blocks[] = 'one_time_task_already_assigned_elsewhere';
        }

        if ($task->advance_notice_days !== null && ! $assignedOnTarget) {
            if ($today->diffInDays($targetDate, false) < $task->advance_notice_days) {
                $blocks[] = 'advance_notice_window_not_met';
            } else {
                $checks[] = 'advance_acceptance_not_recorded';
            }
        }

        if ($task->recurrence_type !== 'one_time' && ! $assignedOnTarget) {
            $checks[] = 'recurrence_due_date_not_evaluated';
        }

        if ($task->day_preference !== null) {
            if ($calendarDay['day_type'] === 'unverified') {
                $checks[] = 'workday_or_holiday_classification_unverified';
            } else {
                $preferenceMismatch = $task->day_preference === 'workday' ? $calendarDay['is_day_off'] : ! $calendarDay['is_day_off'];

                if ($preferenceMismatch && $task->day_preference_is_hard) {
                    $blocks[] = 'day_preference_hard_mismatch';
                } elseif ($preferenceMismatch) {
                    $softFactors[] = 'outside_preferred_day_type';
                }
            }
        }

        if ($task->special_instructions !== null && trim($task->special_instructions) !== '') {
            $checks[] = 'special_instructions_not_checked';
        }

        if ($input?->energy_level === 'low' && $task->energy_cost === 'high') {
            $softFactors[] = 'high_energy_task_on_low_energy_day';
        }

        if ($task->type === 'evening' && $task->energy_cost === 'high'
            && ! $assignedOnTarget && $targetHighEnergyEveningCount >= 1) {
            $softFactors[] = 'would_stack_high_energy_evening_tasks';
        }

        $recentScheduledDates = $recentScheduledDays->pluck('plan_date')->unique()->values();
        $recentSessionDates = $recentSessionCompletions->map(fn (TaskCompletion $completion): string => CarbonImmutable::instance($completion->completed_at)
            ->setTimezone('Europe/Riga')->toDateString())->unique()->values();

        if ($task->is_multi_session && $recentSessionDates->isNotEmpty()) {
            $softFactors[] = 'recent_multi_session_progress';
        }

        if ($recentScheduledDates->contains($targetDate->subDay()->toDateString())
            && $recentScheduledDates->contains($targetDate->subDays(2)->toDateString())) {
            $softFactors[] = 'would_be_third_consecutive_scheduled_day';
        }

        $state = $assignedOnTarget
            ? ($blocks === [] ? 'already_assigned' : 'assigned_with_conflict')
            : ($blocks !== [] ? 'blocked' : ($checks !== [] ? 'needs_checks' : 'preliminary_candidate'));

        return [
            'id' => $task->id,
            'revision' => $task->revision,
            'title' => $task->title,
            'description' => $task->description,
            'special_instructions' => $task->special_instructions,
            'type' => $task->type,
            'priority' => $task->priority,
            'energy_cost' => $task->energy_cost,
            'recurrence_type' => $task->recurrence_type,
            'recurrence_rule' => $task->recurrence_rule,
            'is_multi_session' => $task->is_multi_session,
            'advance_notice_days' => $task->advance_notice_days,
            'day_preference' => $task->day_preference,
            'day_preference_is_hard' => $task->day_preference_is_hard,
            'deadline_on' => $task->deadline_on?->toDateString(),
            'days_until_deadline' => $this->daysUntilDeadline($task->deadline_on, $targetDate),
            'deadline_is_hard' => $task->deadline_is_hard,
            'project' => $task->project === null ? null : [
                'id' => $task->project->id,
                'title' => $task->project->title,
                'priority' => $task->project->priority,
                'deadline_on' => $task->project->deadline_on?->toDateString(),
                'days_until_deadline' => $this->daysUntilDeadline($task->project->deadline_on, $targetDate),
                'deadline_is_hard' => $task->project->deadline_is_hard,
                'lifecycle_state' => $task->project->lifecycle_state,
            ],
            'dependencies' => $task->dependencies->map(fn (Task $dependency): array => [
                'id' => $dependency->id,
                'title' => $dependency->title,
                'lifecycle_state' => $dependency->lifecycle_state,
            ])->all(),
            'completion_count' => $task->completions_count,
            'last_completed_at' => $task->completions_max_completed_at,
            'last_session_completed_at' => $task->is_multi_session ? $task->last_session_completed_at : null,
            'recent_session_completion_dates' => $recentSessionDates->all(),
            'recent_scheduled_dates' => $recentScheduledDates->all(),
            'future_assignment_dates' => $assignments->pluck('plan_date')->all(),
            'last_unfinished_on' => $lastUnfinishedDate,
            'not_before_on' => $notBeforeDate,
            'target_assessment' => [
                'state' => $state,
                'blocking_reasons' => $blocks,
                'blocking_dependency_ids' => $blockingDependencies->pluck('id')->all(),
                'checks_needed' => $checks,
                'soft_factors' => $softFactors,
            ],
        ];
    }

    private function daysUntilDeadline(?CarbonInterface $deadline, CarbonImmutable $targetDate): ?int
    {
        if ($deadline === null) {
            return null;
        }

        $deadlineDate = CarbonImmutable::parse($deadline->toDateString(), 'Europe/Riga');

        return (int) $targetDate->diffInDays($deadlineDate, false);
    }
}

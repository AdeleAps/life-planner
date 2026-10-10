<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class PlanningCapacityPolicy
{
    /**
     * @param  array<string, mixed>  $brief
     * @return array<int, string>
     */
    public function deadlinePressureWarnings(array $brief): array
    {
        $day = $brief['capacity_days'][0];

        $today = CarbonImmutable::now('Europe/Riga')->toDateString();
        $tasks = collect($brief['standing_tasks']);
        $overdueCount = $tasks->filter(fn (array $task): bool => $this->hasDeadlineBefore($task, $today))->count();
        $upcomingDueCount = $tasks->filter(fn (array $task): bool => $this->hasDeadlineBy($task, 0)
            && ! $this->hasDeadlineBefore($task, $today))->count();
        $warnings = [];

        if ($overdueCount > 0) {
            $taskWord = $overdueCount === 1 ? 'task is' : 'tasks are';
            $warnings[] = "$overdueCount open $taskWord past the deadline.";
        }

        if ($upcomingDueCount > 0) {
            $warning = $upcomingDueCount === 1
                ? '1 task has a deadline by tomorrow'
                : "$upcomingDueCount tasks have deadlines by tomorrow";

            if (! $day['goon_mode'] && ! $brief['setup']['day_off']) {
                $dueTasks = $tasks
                    ->filter(fn (array $task): bool => in_array($task['target_assessment']['state'], ['preliminary_candidate', 'already_assigned'], true)
                        && $this->hasDeadlineBy($task, 0));
                $dueEveningCount = $dueTasks->where('type', 'evening')->count();
                $dueDayCount = $dueTasks->where('type', 'day')->count();

                if ($dueEveningCount > $day['ordinary_evening_task_limit']) {
                    $warning .= "; $dueEveningCount Evening tasks may need more than the usual {$day['ordinary_evening_task_limit']}";
                }

                if ($dueDayCount > $day['ordinary_day_task_limit']) {
                    $warning .= "; $dueDayCount Day tasks may need more than the usual {$day['ordinary_day_task_limit']}";
                }
            }

            $warnings[] = "$warning.";
        }

        return $warnings;
    }

    /**
     * @param  array<string, mixed>  $brief
     * @param  array<int, int>  $selectedIds
     * @return array{errors: array<int, string>, warnings: array<int, string>}
     */
    public function assess(array $brief, array $selectedIds): array
    {
        $day = $brief['capacity_days'][0];
        $tasks = collect($brief['standing_tasks'])->keyBy('id');
        $selected = collect($selectedIds)->map(fn (int $id): array => $tasks[$id]);
        $addedEvening = $selected->where('type', 'evening');
        $addedDay = $selected->where('type', 'day');
        $eveningCount = $day['known_evening_task_count'] + $addedEvening->count();
        $dayCount = $day['known_day_task_count'] + $addedDay->count();
        $highEnergyCount = $day['known_high_energy_evening_task_count']
            + $addedEvening->where('energy_cost', 'high')->count();
        $addedHighEnergy = $addedEvening->where('energy_cost', 'high');
        $extraEvening = max(0, $eveningCount - $day['ordinary_evening_task_limit'])
            - max(0, $day['known_evening_task_count'] - $day['ordinary_evening_task_limit']);
        $extraDay = max(0, $dayCount - $day['ordinary_day_task_limit'])
            - max(0, $day['known_day_task_count'] - $day['ordinary_day_task_limit']);
        $pressingEvening = $addedEvening->filter(fn (array $task): bool => $this->hasDeadlineBy($task, 1))->count();
        $pressingDay = $addedDay->filter(fn (array $task): bool => $this->hasDeadlineBy($task, 1))->count();
        $pressingHighEnergy = $addedHighEnergy->filter(fn (array $task): bool => $this->hasDeadlineBy($task, 1))->count();
        $extraHighEnergy = max(0, $highEnergyCount - 1)
            - max(0, $day['known_high_energy_evening_task_count'] - 1);
        $errors = [];
        $warnings = [];

        if ($eveningCount > $day['absolute_evening_task_limit']) {
            $errors[] = 'Too many Evening tasks for tomorrow.';
        } elseif ($extraEvening > $pressingEvening) {
            $errors[] = 'An extra Evening task needs a deadline due very soon.';
        }

        if ($extraDay > $pressingDay) {
            $errors[] = 'More than three Day tasks need deadlines due very soon.';
        }

        if ($highEnergyCount >= 2 && ($extraHighEnergy > $pressingHighEnergy
            || ($brief['setup']['energy_level'] === 'low' && $addedHighEnergy->count() > $pressingHighEnergy))) {
            $errors[] = 'Two high-energy Evening tasks need deadlines due very soon.';
        }

        if ($extraEvening > 0) {
            $warnings[] = 'Tomorrow has more Evening tasks than usual because deadlines are close.';
        }

        if ($extraDay > 0) {
            $warnings[] = 'Tomorrow has more than three Day tasks because deadlines are close.';
        }

        if ($highEnergyCount >= 2) {
            $warnings[] = $brief['setup']['energy_level'] === 'low'
                ? 'Two high-energy Evening tasks are planned on a low-energy day. Check that tomorrow is manageable.'
                : 'Two high-energy Evening tasks are planned together. Check that tomorrow is manageable.';
        }

        $scheduledIds = collect($day['known_assignments'])->pluck('task_id')->merge($selectedIds)->all();
        $unscheduledDeadlines = $tasks->filter(fn (array $task): bool => ! in_array($task['id'], $scheduledIds, true)
            && $this->hasDeadlineBy($task, 0))->count();

        if ($unscheduledDeadlines > 0) {
            $taskWord = $unscheduledDeadlines === 1 ? 'task with a deadline is' : 'tasks with deadlines are';
            $warnings[] = "$unscheduledDeadlines $taskWord not in this plan.";
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /** @param  array<string, mixed>  $task */
    private function hasDeadlineBy(array $task, int $days): bool
    {
        return ($task['days_until_deadline'] !== null && $task['days_until_deadline'] <= $days)
            || ($task['project'] !== null && $task['project']['days_until_deadline'] !== null
                && $task['project']['days_until_deadline'] <= $days);
    }

    /** @param  array<string, mixed>  $task */
    private function hasDeadlineBefore(array $task, string $date): bool
    {
        return ($task['deadline_on'] !== null && $task['deadline_on'] < $date)
            || ($task['project'] !== null && $task['project']['deadline_on'] !== null
                && $task['project']['deadline_on'] < $date);
    }
}

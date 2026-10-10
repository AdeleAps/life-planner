<?php

namespace Tests\Feature;

use App\Models\PlanningDayInput;
use App\Models\ScheduledTask;
use App\Models\Task;
use App\Models\TaskCompletion;
use App\Services\PlanningBriefBuilder;
use App\Services\PlanningCapacityPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanningBriefTest extends TestCase
{
    use RefreshDatabase;

    public function test_brief_reviews_all_active_tasks_and_known_capacity_without_writing_a_plan(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-10 12:00:00', 'Europe/Riga'));

        try {
            $project = Task::factory()->create([
                'item_type' => 'project',
                'title' => 'Prepare the garden',
                'type' => null,
                'deadline_on' => '2026-10-15',
                'deadline_is_hard' => true,
            ]);
            $prerequisite = Task::factory()->create(['title' => 'Get supplies']);
            $blocked = Task::factory()->create([
                'title' => 'Plant bulbs',
                'project_id' => $project->id,
                'energy_cost' => 'high',
                'special_instructions' => 'Check the weather first.',
            ]);
            $blocked->dependencies()->attach($prerequisite);
            $manual = Task::factory()->create(['title' => 'Call a friend']);
            $recurring = Task::factory()->create([
                'title' => 'Clean the kitchen',
                'recurrence_type' => 'schedule',
                'recurrence_rule' => ['unit' => 'week', 'interval' => 1, 'weekdays' => [0]],
            ]);
            $notice = Task::factory()->create(['title' => 'Hike', 'advance_notice_days' => 7]);
            $distantDeadline = Task::factory()->create([
                'title' => 'Buy a car',
                'deadline_on' => '2026-11-21',
                'deadline_is_hard' => true,
            ]);
            Task::factory()->create(['title' => 'Finished', 'lifecycle_state' => 'completed']);

            PlanningDayInput::query()->create([
                'plan_date' => '2026-10-11',
                'energy_level' => 'low',
                'day_off' => false,
                'dinner_enabled' => false,
                'revision' => 1,
            ]);
            ScheduledTask::factory()->create(['task_id' => $manual->id, 'plan_date' => '2026-10-11', 'source' => 'manual']);
            ScheduledTask::factory()->create(['task_id' => $prerequisite->id, 'plan_date' => '2026-10-10']);

            $brief = app(PlanningBriefBuilder::class)->build(CarbonImmutable::parse('2026-10-11', 'Europe/Riga'));
            $tasks = collect($brief['standing_tasks'])->keyBy('id');

            $this->assertSame('2026-10-24', $brief['horizon_end_date']);
            $this->assertCount(14, $brief['capacity_days']);
            $this->assertSame('known_task_counts_only', $brief['snapshot_limits']['capacity']);
            $this->assertSame(1, $brief['pending_review_count']);
            $this->assertSame(6, $tasks->count());
            $this->assertSame('blocked', $tasks[$blocked->id]['target_assessment']['state']);
            $this->assertContains('dependencies_incomplete', $tasks[$blocked->id]['target_assessment']['blocking_reasons']);
            $this->assertContains('high_energy_task_on_low_energy_day', $tasks[$blocked->id]['target_assessment']['soft_factors']);
            $this->assertSame($project->id, $tasks[$blocked->id]['project']['id']);
            $this->assertSame(4, $tasks[$blocked->id]['project']['days_until_deadline']);
            $this->assertSame(41, $tasks[$distantDeadline->id]['days_until_deadline']);
            $this->assertNull($tasks[$notice->id]['days_until_deadline']);
            $this->assertSame('already_assigned', $tasks[$manual->id]['target_assessment']['state']);
            $this->assertSame('needs_checks', $tasks[$recurring->id]['target_assessment']['state']);
            $this->assertContains('advance_notice_window_not_met', $tasks[$notice->id]['target_assessment']['blocking_reasons']);
            $this->assertSame(1, $brief['capacity_days'][0]['known_evening_task_count']);
            $this->assertSame(2, $brief['capacity_days'][0]['known_room_to_absolute_limit']);
            $this->assertSame('day_off', $brief['capacity_days'][0]['workday_classification']);
            $this->assertSame([1], $brief['capacity_days'][0]['comfortable_evening_task_counts']);
            $this->assertSame('Prepare the garden', $brief['capacity_days'][4]['deadlines_on_date'][0]['title']);
            $this->assertSame(1, $brief['projects'][0]['remaining_step_count']);
            $this->assertSame(4, $brief['projects'][0]['days_until_deadline']);

            $this->assertDatabaseCount('scheduled_tasks', 2);
            $this->assertDatabaseCount('planning_day_inputs', 1);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_day_off_blocks_automatic_candidates_but_keeps_manual_assignments_visible(): void
    {
        $manual = Task::factory()->create(['title' => 'A task I chose']);
        $automatic = Task::factory()->create(['title' => 'An automatic candidate']);
        PlanningDayInput::query()->create([
            'plan_date' => '2026-10-11',
            'energy_level' => 'standard',
            'day_off' => true,
            'dinner_enabled' => false,
        ]);
        ScheduledTask::factory()->create(['task_id' => $manual->id, 'plan_date' => '2026-10-11', 'source' => 'manual']);

        $brief = app(PlanningBriefBuilder::class)->build(CarbonImmutable::parse('2026-10-11', 'Europe/Riga'));
        $tasks = collect($brief['standing_tasks'])->keyBy('id');

        $this->assertSame('already_assigned', $tasks[$manual->id]['target_assessment']['state']);
        $this->assertSame('blocked', $tasks[$automatic->id]['target_assessment']['state']);
        $this->assertContains('day_off_manual_only', $tasks[$automatic->id]['target_assessment']['blocking_reasons']);
        $this->assertSame('none_day_off', $brief['capacity_days'][0]['automatic_additions']);
    }

    public function test_recent_multi_session_progress_and_consecutive_days_are_soft_planning_factors(): void
    {
        $task = Task::factory()->create(['title' => 'Finish a game', 'is_multi_session' => true]);
        TaskCompletion::factory()->create([
            'task_id' => $task->id,
            'completion_type' => 'session',
            'completed_at' => CarbonImmutable::parse('2026-10-09 18:00:00', 'Europe/Riga'),
        ]);
        ScheduledTask::factory()->create(['task_id' => $task->id, 'plan_date' => '2026-10-09', 'status' => 'completed']);
        ScheduledTask::factory()->create(['task_id' => $task->id, 'plan_date' => '2026-10-10', 'status' => 'not_completed']);

        $brief = app(PlanningBriefBuilder::class)->build(CarbonImmutable::parse('2026-10-11', 'Europe/Riga'));
        $entry = $brief['standing_tasks'][0];

        $this->assertSame('preliminary_candidate', $entry['target_assessment']['state']);
        $this->assertSame(['2026-10-09'], $entry['recent_session_completion_dates']);
        $this->assertSame(['2026-10-09', '2026-10-10'], $entry['recent_scheduled_dates']);
        $this->assertNotNull($entry['last_session_completed_at']);
        $this->assertContains('recent_multi_session_progress', $entry['target_assessment']['soft_factors']);
        $this->assertContains('would_be_third_consecutive_scheduled_day', $entry['target_assessment']['soft_factors']);
        $this->assertCount(8, $brief['planning_guidance']);
    }

    public function test_weekend_emergency_capacity_and_stacked_high_energy_are_visible_without_overriding_day_off(): void
    {
        $assigned = Task::factory()->create(['title' => 'Climb', 'energy_cost' => 'high']);
        $candidate = Task::factory()->create(['title' => 'Run', 'energy_cost' => 'high']);
        ScheduledTask::factory()->create(['task_id' => $assigned->id, 'plan_date' => '2026-10-11']);

        $brief = app(PlanningBriefBuilder::class)->build(CarbonImmutable::parse('2026-10-11', 'Europe/Riga'));
        $tasks = collect($brief['standing_tasks'])->keyBy('id');
        $sunday = $brief['capacity_days'][0];
        $monday = $brief['capacity_days'][1];

        $this->assertSame(1, $sunday['known_high_energy_evening_task_count']);
        $this->assertSame(2, $sunday['ordinary_evening_task_limit']);
        $this->assertSame(3, $sunday['ordinary_day_task_limit']);
        $this->assertSame(3, $monday['ordinary_day_task_limit']);
        $this->assertSame([1, 2], $sunday['comfortable_evening_task_counts']);
        $this->assertSame(3, $sunday['absolute_evening_task_limit']);
        $this->assertSame(0, $sunday['known_room_to_preferred_high_energy_limit']);
        $this->assertSame(1, $monday['ordinary_evening_task_limit']);
        $this->assertSame(2, $monday['absolute_evening_task_limit']);
        $this->assertSame('confirmed_calendar', $sunday['limit_day_type_basis']);
        $this->assertContains('would_stack_high_energy_evening_tasks', $tasks[$candidate->id]['target_assessment']['soft_factors']);
        $this->assertSame('preliminary_candidate', $tasks[$candidate->id]['target_assessment']['state']);
    }

    public function test_fourth_day_task_requires_an_imminent_deadline(): void
    {
        $tasks = Task::factory()->count(4)->create(['type' => 'day', 'energy_cost' => null]);
        $policy = app(PlanningCapacityPolicy::class);
        $target = CarbonImmutable::parse('2026-10-11', 'Europe/Riga');
        $brief = app(PlanningBriefBuilder::class)->build($target);

        $this->assertContains('More than three Day tasks need deadlines due very soon.', $policy->assess($brief, $tasks->pluck('id')->all())['errors']);

        $tasks->first()->update(['deadline_on' => '2026-10-11', 'deadline_is_hard' => true]);
        $brief = app(PlanningBriefBuilder::class)->build($target);

        $this->assertSame([], $policy->assess($brief, $tasks->pluck('id')->all())['errors']);
    }

    public function test_absolute_evening_limit_still_applies_when_four_deadlines_are_imminent(): void
    {
        $tasks = Task::factory()->count(4)->create(['deadline_on' => '2026-10-11', 'deadline_is_hard' => true]);
        $brief = app(PlanningBriefBuilder::class)->build(CarbonImmutable::parse('2026-10-11', 'Europe/Riga'));

        $this->assertContains('Too many Evening tasks for tomorrow.', app(PlanningCapacityPolicy::class)
            ->assess($brief, $tasks->pluck('id')->all())['errors']);
    }

    public function test_two_high_energy_evening_tasks_on_a_low_energy_day_need_imminent_deadlines(): void
    {
        PlanningDayInput::query()->create([
            'plan_date' => '2026-10-11',
            'energy_level' => 'low',
            'day_off' => false,
            'dinner_enabled' => false,
        ]);
        $tasks = Task::factory()->count(2)->create(['energy_cost' => 'high']);
        $target = CarbonImmutable::parse('2026-10-11', 'Europe/Riga');
        $policy = app(PlanningCapacityPolicy::class);
        $brief = app(PlanningBriefBuilder::class)->build($target);

        $this->assertContains('Two high-energy Evening tasks need deadlines due very soon.', $policy->assess($brief, $tasks->pluck('id')->all())['errors']);

        $tasks->each(fn (Task $task) => $task->update(['deadline_on' => '2026-10-11', 'deadline_is_hard' => true]));
        $brief = app(PlanningBriefBuilder::class)->build($target);

        $this->assertSame([], $policy->assess($brief, $tasks->pluck('id')->all())['errors']);
    }
}

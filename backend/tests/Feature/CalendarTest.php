<?php

namespace Tests\Feature;

use App\Models\HolidaySync;
use App\Models\Task;
use App\Services\LatvianHolidaySync;
use App\Services\PlanningBriefBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CalendarTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_launch_fetches_current_and_next_year_only_once(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-10 12:00:00', 'Europe/Riga'));

        try {
            Http::fake(function ($request) {
                $year = (int) basename($request->url());

                return Http::response([[
                    'date' => "{$year}-11-18",
                    'name' => 'Republic Day',
                    'countryCode' => 'LV',
                    'nationalHoliday' => true,
                    'holidayTypes' => ['Public'],
                ]]);
            });

            $this->getJson('/api/v1/calendar/bootstrap')->assertOk()
                ->assertJsonPath('years.2026', true)->assertJsonPath('years.2027', true);
            $this->getJson('/api/v1/calendar/bootstrap')->assertOk();

            Http::assertSentCount(2);
            $this->assertDatabaseCount('holiday_syncs', 2);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_public_holiday_import_excludes_observances_and_updates_planning_capacity(): void
    {
        Http::fake(['nagerholidays.com/*' => Http::response([
            ['date' => '2026-11-18', 'name' => 'Republic Day', 'countryCode' => 'LV', 'nationalHoliday' => true, 'holidayTypes' => ['Public']],
            ['date' => '2026-11-11', 'name' => 'Lāčplēsis Day', 'countryCode' => 'LV', 'nationalHoliday' => true, 'holidayTypes' => ['Observance']],
        ])]);

        $calendar = $this->getJson('/api/v1/calendar?month=2026-11')->assertOk()->json();
        $days = collect($calendar['days'])->keyBy('date');

        $this->assertTrue($calendar['holiday_year_synced']);
        $this->assertSame('day_off', $days['2026-11-18']['day_type']);
        $this->assertSame(['Republic Day'], $days['2026-11-18']['holiday_names']);
        $this->assertSame('workday', $days['2026-11-11']['day_type']);
        $this->assertDatabaseCount('day_off_periods', 1);
        Http::assertSentCount(1);

        $workdayOnly = Task::factory()->create(['day_preference' => 'workday', 'day_preference_is_hard' => true]);
        $brief = app(PlanningBriefBuilder::class)->build(CarbonImmutable::parse('2026-11-18', 'Europe/Riga'));
        $entry = collect($brief['standing_tasks'])->firstWhere('id', $workdayOnly->id);

        $this->assertSame('synced', $brief['snapshot_limits']['national_holidays']);
        $this->assertSame(2, $brief['capacity_days'][0]['ordinary_evening_task_limit']);
        $this->assertSame(3, $brief['capacity_days'][0]['absolute_evening_task_limit']);
        $this->assertContains('day_preference_hard_mismatch', $entry['target_assessment']['blocking_reasons']);
    }

    public function test_personal_date_range_marks_weekdays_off_and_can_be_removed(): void
    {
        HolidaySync::factory()->create(['year' => 2026]);

        $this->postJson('/api/v1/calendar/days-off', [
            'title' => 'Vacation',
            'start_on' => '2026-11-20',
            'end_on' => '2026-11-23',
        ])->assertCreated();

        $calendar = $this->getJson('/api/v1/calendar?month=2026-11')->assertOk()->json();
        $days = collect($calendar['days'])->keyBy('date');
        $periodId = $calendar['personal_days_off'][0]['id'];

        $this->assertSame('day_off', $days['2026-11-20']['day_type']);
        $this->assertSame('day_off', $days['2026-11-23']['day_type']);
        $this->assertSame('Vacation', $days['2026-11-23']['personal_days_off'][0]['title']);
        $this->assertSame(3, app(PlanningBriefBuilder::class)->build(CarbonImmutable::parse('2026-11-23', 'Europe/Riga'))['capacity_days'][0]['absolute_evening_task_limit']);

        $this->deleteJson("/api/v1/calendar/days-off/{$periodId}")->assertOk();
        $this->assertSame('workday', collect($this->getJson('/api/v1/calendar?month=2026-11')->assertOk()->json('days'))
            ->firstWhere('date', '2026-11-23')['day_type']);
        $this->assertDatabaseCount('day_off_periods', 0);
    }

    public function test_goon_mode_pauses_planning_and_can_be_edited_off(): void
    {
        HolidaySync::factory()->create(['year' => 2026]);
        $task = Task::factory()->create();

        $period = $this->postJson('/api/v1/calendar/days-off', [
            'title' => 'Vacation',
            'start_on' => '2026-11-20',
            'end_on' => '2026-11-23',
            'goon_mode' => true,
        ])->assertCreated()->assertJsonPath('data.goon_mode', true)->json('data');

        $days = collect($this->getJson('/api/v1/calendar?month=2026-11')->assertOk()->json('days'))->keyBy('date');
        $this->assertTrue($days['2026-11-23']['goon_mode']);

        $brief = app(PlanningBriefBuilder::class)->build(CarbonImmutable::parse('2026-11-23', 'Europe/Riga'));
        $this->assertSame('none_goon_mode', $brief['capacity_days'][0]['automatic_additions']);
        $this->assertSame(0, $brief['capacity_days'][0]['absolute_evening_task_limit']);
        $this->assertContains('goon_mode', collect($brief['standing_tasks'])->firstWhere('id', $task->id)['target_assessment']['blocking_reasons']);

        $this->putJson("/api/v1/calendar/days-off/{$period['id']}", [
            'title' => 'Short break',
            'start_on' => '2026-11-20',
            'end_on' => '2026-11-23',
            'goon_mode' => false,
        ])->assertOk()->assertJsonPath('data.goon_mode', false);

        $updatedDay = collect($this->getJson('/api/v1/calendar?month=2026-11')->assertOk()->json('days'))
            ->firstWhere('date', '2026-11-23');
        $this->assertFalse($updatedDay['goon_mode']);
        $this->assertSame('Short break', $updatedDay['personal_days_off'][0]['title']);
    }

    public function test_import_adds_statutory_monday_when_november_holiday_falls_on_weekend(): void
    {
        Http::fake(['nagerholidays.com/*' => Http::response([
            ['date' => '2028-11-18', 'name' => 'Republic Day', 'countryCode' => 'LV', 'nationalHoliday' => true, 'holidayTypes' => ['Public']],
        ])]);

        app(LatvianHolidaySync::class)->syncYear(2028);

        $this->assertDatabaseHas('day_off_periods', ['source' => 'national', 'start_on' => '2028-11-20']);
        $this->assertDatabaseCount('day_off_periods', 2);
    }
}

<?php

namespace Tests\Feature;

use App\Models\PlanningDayInput;
use App\Models\ScheduledTask;
use App\Models\Task;
use App\Services\TaskResearcher;
use App\Services\WeatherForecastResearch;
use App\Services\WebPushSender;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

class AdvanceOfferPlanningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-10 19:00:00', 'Europe/Riga'));
        config(['services.openai.key' => 'test-key']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_a_sourced_future_date_is_offered_with_the_notice_conflict_and_accepted_once(): void
    {
        $hike = $this->hike();
        $this->fakeResearch('2026-10-18', true);

        $offer = $this->postJson('/api/v1/advance-offers/refresh')->assertOk()->json('data.0');
        $this->assertSame('one_time', $offer['recurrence_type']);

        $this->assertSame($hike->id, $offer['task_id']);
        $this->assertSame('2026-10-18', $offer['proposed_on']);
        $this->assertSame(4, $offer['notice_shortfall_days']);
        $this->assertSame('https://example.org/forecast', $offer['research_result']['sources'][0]['url']);
        $this->assertSame(30, DB::table('ai_monthly_budgets')->value('allowance_cents'));

        $this->postJson('/api/v1/advance-offers/refresh')->assertOk();
        Http::assertSentCount(1);

        $this->postJson("/api/v1/advance-offers/{$offer['id']}/accept")->assertOk();
        $this->assertDatabaseHas('scheduled_tasks', [
            'task_id' => $hike->id, 'plan_date' => '2026-10-18',
            'source' => 'advance_accepted', 'status' => 'planned',
        ]);
        $this->getJson('/api/v1/planning-days/2026-10-18/tasks')->assertOk()
            ->assertJsonPath('data.0.source', 'advance_accepted')
            ->assertJsonPath('data.0.research_result.status', 'ready');
        $this->getJson('/api/v1/advance-offers')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/v1/advance-offers/{$offer['id']}/accept")->assertStatus(422);
    }

    public function test_completed_research_can_offer_a_day_without_a_link(): void
    {
        $this->hike();
        $this->fakeResearch('2026-10-18', false);

        $offer = $this->postJson('/api/v1/advance-offers/refresh')->assertOk()->json('data.0');

        $this->assertSame('offered', $offer['status']);
        $this->assertSame('2026-10-18', $offer['proposed_on']);
        $this->assertSame([], $offer['research_result']['sources']);
        $this->postJson('/api/v1/advance-offers/refresh')->assertOk();
        Http::assertSentCount(1);
        $this->assertDatabaseCount('scheduled_tasks', 0);
    }

    public function test_riga_hike_uses_daily_forecast_and_offers_a_dry_date_without_ai_or_a_link(): void
    {
        $hike = Task::factory()->create([
            'title' => 'Hike near Riga', 'advance_notice_days' => 12,
            'deadline_on' => '2026-10-20', 'deadline_is_hard' => true,
            'special_instructions' => 'Check the forecast near Riga. Only plan if dry and suitable for walking.',
        ]);
        $this->fakeRigaForecast();

        $offer = $this->postJson('/api/v1/advance-offers/refresh')->assertOk()->json('data.0');

        $this->assertSame($hike->id, $offer['task_id']);
        $this->assertSame('offered', $offer['status']);
        $this->assertSame('2026-10-17', $offer['proposed_on']);
        $this->assertSame(5, $offer['notice_shortfall_days']);
        $this->assertSame([], $offer['research_result']['sources']);
        $this->assertSame('Open-Meteo', $offer['research_result']['evidence']['provider']);
        $this->assertSame(0, DB::table('ai_monthly_budgets')->count());
        $this->postJson('/api/v1/advance-offers/refresh')->assertOk();
        Http::assertSentCount(1);

        $this->postJson("/api/v1/advance-offers/{$offer['id']}/accept")->assertOk();
        $this->assertDatabaseHas('scheduled_tasks', [
            'task_id' => $hike->id, 'plan_date' => '2026-10-17',
            'source' => 'advance_accepted', 'status' => 'planned',
        ]);
    }

    public function test_forecast_failure_is_not_described_as_bad_weather(): void
    {
        Task::factory()->create([
            'title' => 'Hike near Riga', 'advance_notice_days' => 7,
            'special_instructions' => 'Check the Riga weather before hiking.',
        ]);
        Http::fake(['api.open-meteo.com/*' => Http::response([], 503)]);

        $this->postJson('/api/v1/advance-offers/refresh')->assertOk()
            ->assertJsonPath('data.0.status', 'unavailable')
            ->assertJsonPath('data.0.research_result.summary', 'Could not load the Riga forecast right now.');
    }

    public function test_a_weather_hike_without_a_location_uses_riga_without_ai(): void
    {
        Task::factory()->create([
            'title' => 'Hike', 'advance_notice_days' => 7,
            'special_instructions' => 'Check weather first; do not plan when it is raining.',
        ]);
        $this->fakeRigaForecast();

        $this->postJson('/api/v1/advance-offers/refresh')->assertOk()
            ->assertJsonPath('data.0.status', 'offered')
            ->assertJsonPath('data.0.proposed_on', '2026-10-17')
            ->assertJsonPath('data.0.research_result.evidence.location', 'Riga');
        Http::assertSentCount(1);
    }

    public function test_a_stale_missing_location_result_is_rechecked_immediately_with_riga_default(): void
    {
        $hike = Task::factory()->create([
            'title' => 'Hike', 'advance_notice_days' => 7,
            'special_instructions' => 'Check weather before hiking.',
        ]);
        DB::table('advance_offers')->insert([
            'task_id' => $hike->id, 'task_revision' => $hike->revision,
            'status' => 'unavailable', 'proposed_on' => null,
            'research_result' => json_encode(['status' => 'unknown',
                'summary' => 'Add a location to this task so Planner can check the weather.', 'sources' => []]),
            'next_check_on' => '2026-10-12', 'notice_shortfall_days' => 0,
            'allowance_cents' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->fakeRigaForecast();

        $this->postJson('/api/v1/advance-offers/refresh')->assertOk()
            ->assertJsonPath('data.0.status', 'offered')
            ->assertJsonPath('data.0.proposed_on', '2026-10-17')
            ->assertJsonPath('data.0.research_result.evidence.location', 'Riga');
        Http::assertSentCount(1);
    }

    public function test_extra_hike_instructions_are_researched_after_weather_is_checked(): void
    {
        $hike = Task::factory()->create([
            'title' => 'Hike',
            'special_instructions' => 'Only go hiking in good weather. Also give me two ideas within 50 km of Riga.',
        ]);
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'api.open-meteo.com')) {
                return Http::response(['daily' => [
                    'time' => ['2026-10-17'], 'precipitation_probability_max' => [20],
                    'precipitation_sum' => [0], 'weather_code' => [2], 'temperature_2m_max' => [13],
                ]]);
            }

            return Http::response(['status' => 'completed', 'output' => [
                ['type' => 'web_search_call', 'status' => 'completed', 'action' => ['sources' => []]],
                ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
                    'status' => 'ready', 'summary' => 'Try Jūrmala beach or Ogre’s riverside trails.',
                    'valid_until_on' => '2026-10-17',
                ])]]],
            ]]);
        });

        $result = app(TaskResearcher::class)->check(
            $hike->only(['id', 'revision', 'title', 'description', 'special_instructions']), '2026-10-17',
        );

        $this->assertSame('ready', $result['status']);
        $this->assertStringContainsString('Jūrmala', $result['summary']);
        Http::assertSentCount(2);
        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), 'api.openai.com')) {
                return false;
            }

            $input = json_decode($request['input'], true);

            return ($input['verified_weather']['evidence']['location'] ?? null) === 'Riga'
                && ($input['verified_weather']['status'] ?? null) === 'ready';
        });
    }

    public function test_an_explicit_other_place_does_not_use_riga_forecast(): void
    {
        $this->assertFalse(app(WeatherForecastResearch::class)->supports([
            'title' => 'Hike near Cēsis',
            'description' => null,
            'special_instructions' => 'Check the weather before hiking.',
        ]));
    }

    public function test_a_riga_hike_check_uses_the_forecast_once_per_day(): void
    {
        $hike = Task::factory()->create([
            'title' => 'Hike near Riga',
            'special_instructions' => 'Check the Riga forecast. Only go hiking when dry.',
        ]);
        $this->fakeRigaForecast();
        $task = $hike->only(['id', 'revision', 'title', 'description', 'special_instructions']);
        $researcher = app(TaskResearcher::class);

        $result = $researcher->check($task, '2026-10-17');
        $this->assertSame('ready', $result['status']);
        $this->assertSame('2026-10-17', $result['valid_until_on']);
        $this->assertSame('Open-Meteo', $result['evidence']['provider']);
        $this->assertEquals($result, $researcher->check($task, '2026-10-17'));
        Http::assertSentCount(1);
        $this->assertDatabaseHas('task_research_checks', [
            'task_id' => $hike->id, 'plan_date' => '2026-10-17',
            'status' => 'completed', 'allowance_cents' => 0,
        ]);
    }

    public function test_an_unverified_forecast_explains_why_no_date_was_offered(): void
    {
        $this->hike();
        Http::fake(['api.openai.com/*' => Http::response([
            'status' => 'completed', 'output' => [
                ['type' => 'web_search_call', 'status' => 'completed', 'action' => [
                    'sources' => [['title' => 'Forecast', 'url' => 'https://example.org/forecast']],
                ]],
                ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
                    'status' => 'unknown', 'proposed_on' => '',
                    'summary' => 'The available forecast does not yet cover a dry day before the deadline.',
                ])]]],
            ],
        ])]);

        $this->postJson('/api/v1/advance-offers/refresh')->assertOk()
            ->assertJsonPath('data.0.status', 'unavailable')
            ->assertJsonPath('data.0.research_result.summary',
                'The available forecast does not yet cover a dry day before the deadline.');
    }

    public function test_a_cited_source_is_accepted_when_the_search_source_list_is_empty(): void
    {
        $this->hike();
        Http::fake(['api.openai.com/*' => Http::response([
            'status' => 'completed', 'output' => [
                ['type' => 'web_search_call', 'status' => 'completed', 'action' => ['sources' => []]],
                ['type' => 'message', 'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode(['status' => 'ready', 'proposed_on' => '2026-10-18',
                        'summary' => 'A dry day is forecast.']),
                    'annotations' => [['type' => 'url_citation', 'title' => 'Forecast',
                        'url' => 'https://example.org/forecast']],
                ]]],
            ],
        ])]);

        $this->postJson('/api/v1/advance-offers/refresh')->assertOk()
            ->assertJsonPath('data.0.status', 'offered')
            ->assertJsonPath('data.0.research_result.sources.0.url', 'https://example.org/forecast');
    }

    public function test_an_offer_cannot_be_accepted_after_its_date_becomes_unavailable(): void
    {
        $this->hike();
        $this->fakeResearch('2026-10-18', true);
        $offer = $this->postJson('/api/v1/advance-offers/refresh')->assertOk()->json('data.0');

        PlanningDayInput::query()->create([
            'plan_date' => '2026-10-18', 'energy_level' => 'standard',
            'day_off' => true, 'dinner_enabled' => false, 'revision' => 1,
        ]);

        $this->postJson("/api/v1/advance-offers/{$offer['id']}/accept")->assertStatus(422);
        $this->assertDatabaseCount('scheduled_tasks', 0);
    }

    public function test_an_accepted_task_is_checked_again_the_evening_before_without_moving_it(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-17 20:00:00', 'Europe/Riga'));
        $hike = $this->hike();
        ScheduledTask::query()->create([
            'task_id' => $hike->id, 'plan_date' => '2026-10-18',
            'source' => 'advance_accepted', 'status' => 'planned',
        ]);
        PlanningDayInput::query()->create([
            'plan_date' => '2026-10-18', 'energy_level' => 'standard',
            'day_off' => false, 'dinner_enabled' => false, 'revision' => 1,
        ]);
        Http::fake(function ($request) {
            if (($request['tools'][0]['type'] ?? null) === 'web_search') {
                return Http::response([
                    'status' => 'completed', 'output' => [
                        ['type' => 'web_search_call', 'status' => 'completed', 'action' => [
                            'type' => 'search', 'sources' => [['title' => 'Forecast', 'url' => 'https://example.org/forecast']],
                        ]],
                        ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
                            'status' => 'unsuitable', 'summary' => 'Heavy rain is forecast.',
                            'valid_until_on' => '2026-10-18',
                        ])]]],
                    ],
                ]);
            }

            return Http::response(['status' => 'completed', 'output' => [[
                'content' => [['type' => 'output_text', 'text' => json_encode(['task_ids' => [], 'note' => ''])]],
            ]]]);
        });

        $draft = $this->postJson('/api/v1/planning-days/2026-10-18/draft', [
            'client_request_id' => (string) Str::uuid(),
        ])->assertOk();

        $draft->assertJsonPath('data.accepted_advance_checks.0.task_id', $hike->id)
            ->assertJsonPath('data.accepted_advance_checks.0.result.status', 'unsuitable');
        $this->assertDatabaseHas('scheduled_tasks', [
            'task_id' => $hike->id, 'plan_date' => '2026-10-18', 'status' => 'planned',
        ]);
        $this->getJson('/api/v1/planning-days/2026-10-18/tasks')->assertOk()
            ->assertJsonPath('data.0.research_result.status', 'unsuitable');
        Http::assertSentCount(2);
    }

    public function test_an_unresolved_notice_conflict_sends_one_advance_warning(): void
    {
        $hike = $this->hike();
        DB::table('advance_offers')->insert([
            'task_id' => $hike->id, 'task_revision' => $hike->revision,
            'status' => 'unavailable', 'proposed_on' => null,
            'research_result' => json_encode(['status' => 'unknown', 'summary' => 'Forecast not available.',
                'sources' => [], 'check_version' => 2]),
            'next_check_on' => '2026-10-12', 'notice_shortfall_days' => 0,
            'allowance_cents' => 30, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->mock(WebPushSender::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sendAdvanceOfferReminder')->once()
                ->with('Hike near Riga', true)->andReturn(['sent' => 1, 'failed' => 0]);
        });

        $this->artisan('planner:refresh-advance-offers')->assertSuccessful();
        $this->artisan('planner:refresh-advance-offers')->assertSuccessful();

        $this->assertNotNull(DB::table('advance_offers')->where('task_id', $hike->id)->value('notified_at'));
    }

    private function hike(): Task
    {
        return Task::factory()->create([
            'title' => 'Hike near Riga', 'advance_notice_days' => 12,
            'deadline_on' => '2026-10-20', 'deadline_is_hard' => true,
            'special_instructions' => 'Check the weather and parking and choose a good day for hiking.',
        ]);
    }

    private function fakeResearch(string $date, bool $withSource): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'status' => 'completed', 'output' => [
                ['type' => 'web_search_call', 'status' => 'completed', 'action' => [
                    'type' => 'search', 'sources' => $withSource
                        ? [['title' => 'Forecast', 'url' => 'https://example.org/forecast']] : [],
                ]],
                ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
                    'status' => 'ready', 'proposed_on' => $date,
                    'summary' => 'The forecast suggests a dry hiking day.',
                ])]]],
            ],
        ])]);
    }

    private function fakeRigaForecast(): void
    {
        Http::fake(['api.open-meteo.com/*' => Http::response(['daily' => [
            'time' => ['2026-10-16', '2026-10-17', '2026-10-18', '2026-10-21'],
            'precipitation_probability_max' => [14, 20, 85, 25],
            'precipitation_sum' => [0, 0, 6, 0],
            'weather_code' => [3, 2, 61, 2],
            'temperature_2m_max' => [13, 13, 10, 11],
        ]])]);
    }
}

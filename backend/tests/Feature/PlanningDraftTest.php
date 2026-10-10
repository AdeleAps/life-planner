<?php

namespace Tests\Feature;

use App\Models\PlanningDayInput;
use App\Models\Task;
use App\Services\PlanningBriefBuilder;
use App\Services\TaskResearcher;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlanningDraftTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-10 19:00:00', 'Europe/Riga'));
        config(['services.openai.key' => 'test-key']);
        PlanningDayInput::query()->create([
            'plan_date' => '2026-10-11',
            'energy_level' => 'standard',
            'day_off' => false,
            'dinner_enabled' => false,
            'revision' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_ai_draft_is_read_only_and_repeated_request_does_not_call_ai_again(): void
    {
        $task = Task::factory()->create(['deadline_on' => '2026-11-21', 'deadline_is_hard' => true]);
        Http::fake(['api.openai.com/*' => Http::response([
            'status' => 'completed',
            'output' => [['content' => [['type' => 'output_text', 'text' => json_encode(['task_ids' => [$task->id], 'note' => 'A good day to make progress. Another thought.'])]]]],
            'usage' => ['input_tokens' => 123, 'output_tokens' => 25],
        ])]);

        $requestId = (string) Str::uuid();
        $url = '/api/v1/planning-days/2026-10-11/draft';
        $first = $this->postJson($url, ['client_request_id' => $requestId]);
        $first->assertOk()->assertJsonPath('data.task_ids.0', $task->id)
            ->assertJsonPath('data.note', 'A good day to make progress.')
            ->assertJsonPath('data.published', false);

        $this->postJson($url, ['client_request_id' => $requestId])->assertOk();
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request['model'] === 'gpt-6-luna'
            && $request['reasoning']['effort'] === 'xhigh'
            && $request['max_output_tokens'] === 4000
            && $request['tools'] === []
            && collect(json_decode($request['input'], true)['standing_tasks'])->firstWhere('id', $task->id)['days_until_deadline'] === 41);
        $this->assertDatabaseCount('scheduled_tasks', 0);
        $this->assertSame(20, DB::table('ai_monthly_budgets')->value('allowance_cents'));
    }

    public function test_monthly_allowance_prevents_an_ai_call(): void
    {
        DB::table('ai_monthly_budgets')->insert([
            'month' => '2026-10',
            'allowance_cents' => 1500,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Http::fake();

        $this->postJson('/api/v1/planning-days/2026-10-11/draft', [
            'client_request_id' => (string) Str::uuid(),
        ])->assertStatus(429);

        Http::assertNothingSent();
    }

    public function test_missing_api_key_prevents_an_ai_call_without_using_allowance(): void
    {
        config(['services.openai.key' => null]);
        Http::fake();

        $this->postJson('/api/v1/planning-days/2026-10-11/draft', [
            'client_request_id' => (string) Str::uuid(),
        ])->assertStatus(503);

        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_planning_requests', 0);
    }

    public function test_only_a_shortlisted_special_instruction_is_researched_and_can_be_saved_with_sources(): void
    {
        $task = Task::factory()->create([
            'special_instructions' => 'Look up a real cinema screening in Riga tomorrow evening.',
            'deadline_on' => '2026-10-11', 'deadline_is_hard' => true,
        ]);
        Task::factory()->create(['special_instructions' => 'Look up parking before planning.']);
        Http::fake(function ($request) use ($task) {
            if (($request['tools'][0]['type'] ?? null) === 'web_search') {
                return Http::response([
                    'status' => 'completed',
                    'output' => [
                        ['type' => 'web_search_call', 'status' => 'completed', 'action' => [
                            'type' => 'search', 'sources' => [['title' => 'Cinema schedule', 'url' => 'https://example.org/screening']],
                        ]],
                        ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
                            'status' => 'ready', 'summary' => 'A screening starts at 19:00. ([Cinema](https://example.org/screening))',
                            'valid_until_on' => '2026-10-11',
                        ])]]],
                    ],
                    'usage' => ['input_tokens' => 99, 'output_tokens' => 30],
                ]);
            }

            return Http::response([
                'status' => 'completed',
                'output' => [['content' => [['type' => 'output_text', 'text' => json_encode([
                    'task_ids' => [$task->id], 'note' => '',
                ])]]]],
            ]);
        });

        $requestId = (string) Str::uuid();
        $draft = $this->postJson('/api/v1/planning-days/2026-10-11/draft', [
            'client_request_id' => $requestId,
        ])->assertOk();

        $draft->assertJsonPath("data.research_results.{$task->id}.status", 'ready')
            ->assertJsonPath("data.research_results.{$task->id}.summary", 'A screening starts at 19:00.')
            ->assertJsonPath("data.research_results.{$task->id}.sources.0.url", 'https://example.org/screening');
        $this->assertSame([], $draft->json('data.needs_checks_task_ids'));
        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => ($request['tools'][0]['type'] ?? null) === 'web_search'
            && $request['max_tool_calls'] === 2 && $request['tool_choice'] === 'required');
        $this->assertDatabaseCount('task_research_checks', 1);
        $this->assertSame(50, DB::table('ai_monthly_budgets')->value('allowance_cents'));

        $this->postJson('/api/v1/planning-days/2026-10-11/plan', [
            'client_request_id' => $requestId, 'task_ids' => [$task->id],
        ])->assertOk()->assertJsonPath('data.published', true);
        $this->assertDatabaseHas('scheduled_tasks', ['task_id' => $task->id, 'plan_date' => '2026-10-11']);
        $this->getJson('/api/v1/planning-days/2026-10-11/tasks')->assertOk()
            ->assertJsonPath('data.0.research_result.summary', 'A screening starts at 19:00.')
            ->assertJsonPath('data.0.research_result.sources.0.url', 'https://example.org/screening');
    }

    public function test_a_check_without_a_completed_search_cannot_be_saved_as_complete(): void
    {
        $task = Task::factory()->create(['special_instructions' => 'Check the weather before planning.']);
        Http::fake(function ($request) use ($task) {
            if (($request['tools'][0]['type'] ?? null) === 'web_search') {
                return Http::response([
                    'status' => 'completed',
                    'output' => [
                        ['type' => 'web_search_call', 'status' => 'failed', 'action' => ['type' => 'search', 'sources' => []]],
                        ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
                            'status' => 'ready', 'summary' => 'It looks dry.', 'valid_until_on' => '2026-10-11',
                        ])]]],
                    ],
                ]);
            }

            return Http::response(['status' => 'completed', 'output' => [['content' => [[
                'type' => 'output_text', 'text' => json_encode(['task_ids' => [$task->id], 'note' => '']),
            ]]]]]);
        });

        $requestId = (string) Str::uuid();
        $draft = $this->postJson('/api/v1/planning-days/2026-10-11/draft', [
            'client_request_id' => $requestId,
        ])->assertOk();
        $draft->assertJsonPath("data.research_results.{$task->id}.status", 'unknown');
        $this->assertSame([$task->id], $draft->json('data.needs_checks_task_ids'));

        $this->postJson('/api/v1/planning-days/2026-10-11/plan', [
            'client_request_id' => $requestId, 'task_ids' => [$task->id],
        ])->assertStatus(422);
        $this->assertDatabaseCount('scheduled_tasks', 0);
    }

    public function test_a_hard_deadline_due_tomorrow_triggers_research_even_when_not_shortlisted(): void
    {
        $due = Task::factory()->create([
            'deadline_on' => '2026-10-11', 'deadline_is_hard' => true,
            'special_instructions' => 'Look up a real screening tomorrow evening.',
        ]);
        $chosen = Task::factory()->create();
        Http::fake(function ($request) use ($chosen, $due) {
            if (($request['tools'][0]['type'] ?? null) === 'web_search') {
                return Http::response([
                    'status' => 'completed',
                    'output' => [
                        ['type' => 'web_search_call', 'status' => 'completed', 'action' => [
                            'type' => 'search', 'sources' => [['url' => 'https://example.org/film', 'title' => 'Film']],
                        ]],
                        ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
                            'status' => 'ready', 'summary' => 'A screening is available.',
                            'valid_until_on' => '2026-10-11',
                        ])]]],
                    ],
                ]);
            }

            $brief = json_decode($request['input'], true);
            $this->assertSame('ready', $brief['preflight_research'][$due->id]['status']);

            return Http::response(['status' => 'completed', 'output' => [['content' => [[
                'type' => 'output_text', 'text' => json_encode(['task_ids' => [$chosen->id], 'note' => '']),
            ]]]]]);
        });

        $draft = $this->postJson('/api/v1/planning-days/2026-10-11/draft', [
            'client_request_id' => (string) Str::uuid(),
        ])->assertOk();

        $draft->assertJsonPath('data.other_checked_tasks.0.id', $due->id)
            ->assertJsonPath('data.other_checked_tasks.0.result.status', 'ready');
        Http::assertSentCount(2);
        $this->assertDatabaseCount('task_research_checks', 1);
    }

    public function test_still_valid_stable_research_is_reused_for_another_day(): void
    {
        $task = Task::factory()->create(['special_instructions' => 'Look up nearby parking.']);
        Http::fake(['api.openai.com/*' => Http::response([
            'status' => 'completed',
            'output' => [
                ['type' => 'web_search_call', 'status' => 'completed', 'action' => [
                    'type' => 'search', 'sources' => [['title' => 'Parking', 'url' => 'https://example.org/parking']],
                ]],
                ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
                    'status' => 'ready', 'summary' => 'Parking is available nearby.',
                    'valid_until_on' => '2026-10-20',
                ])]]],
            ],
        ])]);

        $entry = collect(app(PlanningBriefBuilder::class)->build(CarbonImmutable::parse('2026-10-11', 'Europe/Riga'))['standing_tasks'])
            ->firstWhere('id', $task->id);
        $researcher = app(TaskResearcher::class);
        $first = $researcher->check($entry, '2026-10-11');
        $task->update(['revision' => 2]);
        $entry['revision'] = 2;
        $second = $researcher->check($entry, '2026-10-12');

        $this->assertSame($first, $second);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('task_research_checks', 2);
        $this->assertSame(30, DB::table('ai_monthly_budgets')->value('allowance_cents'));
    }

    public function test_date_specific_research_is_checked_again_for_another_day(): void
    {
        $task = Task::factory()->create(['special_instructions' => 'Check the weather for the planned day.']);
        Http::fake(function ($request) {
            return Http::response([
                'status' => 'completed',
                'output' => [
                    ['type' => 'web_search_call', 'status' => 'completed', 'action' => [
                        'type' => 'search', 'sources' => [['title' => 'Forecast', 'url' => 'https://example.org/forecast']],
                    ]],
                    ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
                        'status' => 'ready', 'summary' => 'The forecast is suitable.', 'valid_until_on' => '2026-10-20',
                    ])]]],
                ],
            ]);
        });

        $entry = collect(app(PlanningBriefBuilder::class)->build(CarbonImmutable::parse('2026-10-11', 'Europe/Riga'))['standing_tasks'])
            ->firstWhere('id', $task->id);
        $researcher = app(TaskResearcher::class);
        $first = $researcher->check($entry, '2026-10-11');
        $researcher->check($entry, '2026-10-12');

        $this->assertSame('2026-10-11', $first['valid_until_on']);
        Http::assertSentCount(2);
        $this->assertSame(60, DB::table('ai_monthly_budgets')->value('allowance_cents'));
    }

    public function test_more_than_three_drafts_for_one_day_are_allowed(): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'status' => 'completed',
            'output' => [['content' => [['type' => 'output_text', 'text' => json_encode(['task_ids' => [], 'note' => ''])]]]],
        ])]);

        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->postJson('/api/v1/planning-days/2026-10-11/draft', [
                'client_request_id' => (string) Str::uuid(),
            ])->assertOk();
        }

        Http::assertSentCount(4);
        $this->assertSame(80, DB::table('ai_monthly_budgets')->value('allowance_cents'));
    }

    public function test_deadlines_stay_visible_even_before_setup_or_an_ai_draft(): void
    {
        PlanningDayInput::query()->delete();
        Task::factory()->create(['deadline_on' => '2026-10-09', 'deadline_is_hard' => true]);
        Task::factory()->create(['deadline_on' => '2026-10-11', 'deadline_is_hard' => false]);

        $response = $this->getJson('/api/v1/planning-days/2026-10-11/plan')->assertOk();

        $this->assertContains('1 open task is past the deadline.', $response->json('data.deadline_pressure_warnings'));
        $this->assertContains('1 task has a deadline by tomorrow.', $response->json('data.deadline_pressure_warnings'));
    }

    public function test_imminent_deadlines_can_fill_an_extra_evening_slot_with_visible_low_energy_warning(): void
    {
        PlanningDayInput::query()->update(['energy_level' => 'low']);
        $first = Task::factory()->create(['deadline_on' => '2026-10-11', 'deadline_is_hard' => true, 'energy_cost' => 'high']);
        $second = Task::factory()->create(['deadline_on' => '2026-10-11', 'deadline_is_hard' => true, 'energy_cost' => 'high']);
        $third = Task::factory()->create(['deadline_on' => '2026-10-11', 'deadline_is_hard' => false]);
        $ids = [$first->id, $second->id, $third->id];
        Http::fake(['api.openai.com/*' => Http::response([
            'status' => 'completed',
            'output' => [['content' => [['type' => 'output_text', 'text' => json_encode(['task_ids' => $ids, 'note' => 'A fuller day is needed for these deadlines.'])]]]],
        ])]);

        $requestId = (string) Str::uuid();
        $draft = $this->postJson('/api/v1/planning-days/2026-10-11/draft', ['client_request_id' => $requestId])->assertOk();
        $this->assertContains('Tomorrow has more Evening tasks than usual because deadlines are close.', $draft->json('data.capacity_warnings'));
        $this->assertContains('Two high-energy Evening tasks are planned on a low-energy day. Check that tomorrow is manageable.', $draft->json('data.capacity_warnings'));

        $plan = $this->postJson('/api/v1/planning-days/2026-10-11/plan', [
            'client_request_id' => $requestId,
            'task_ids' => $ids,
        ])->assertOk()->assertJsonPath('data.published', true);
        $this->assertContains('3 tasks have deadlines by tomorrow; 3 Evening tasks may need more than the usual 2.', $plan->json('data.deadline_pressure_warnings'));
        $this->assertDatabaseCount('scheduled_tasks', 3);
    }

    public function test_extra_evening_task_without_a_near_deadline_is_rejected(): void
    {
        $tasks = Task::factory()->count(3)->create();
        Http::fake(['api.openai.com/*' => Http::response([
            'status' => 'completed',
            'output' => [['content' => [['type' => 'output_text', 'text' => json_encode(['task_ids' => $tasks->pluck('id')->all(), 'note' => ''])]]]],
        ])]);

        $this->postJson('/api/v1/planning-days/2026-10-11/draft', [
            'client_request_id' => (string) Str::uuid(),
        ])->assertStatus(502)->assertJsonPath('message', 'The AI suggested too many tasks for tomorrow. Try again.');

        $this->assertDatabaseCount('scheduled_tasks', 0);
    }

    public function test_unscheduled_soft_and_hard_deadlines_are_called_out_in_the_draft(): void
    {
        $chosen = Task::factory()->create();
        Task::factory()->create(['deadline_on' => '2026-10-11', 'deadline_is_hard' => true]);
        Task::factory()->create(['deadline_on' => '2026-10-11', 'deadline_is_hard' => false]);
        Http::fake(['api.openai.com/*' => Http::response([
            'status' => 'completed',
            'output' => [['content' => [['type' => 'output_text', 'text' => json_encode(['task_ids' => [$chosen->id], 'note' => ''])]]]],
        ])]);

        $draft = $this->postJson('/api/v1/planning-days/2026-10-11/draft', [
            'client_request_id' => (string) Str::uuid(),
        ])->assertOk();

        $this->assertContains('2 tasks with deadlines are not in this plan.', $draft->json('data.capacity_warnings'));

        $this->postJson('/api/v1/planning-days/2026-10-11/plan', [
            'client_request_id' => $draft->json('data.client_request_id'),
            'task_ids' => [$chosen->id],
        ])->assertOk()->assertJsonPath('data.published', true);

        $saved = $this->getJson('/api/v1/planning-days/2026-10-11/plan')->assertOk();
        $this->assertContains('2 tasks with deadlines are not in this plan.', $saved->json('data.draft.capacity_warnings'));
    }

    public function test_rejecting_a_blocked_ai_choice_never_publishes_it(): void
    {
        $dependency = Task::factory()->create();
        $blocked = Task::factory()->create();
        $blocked->dependencies()->attach($dependency);
        Http::fake(['api.openai.com/*' => Http::response([
            'status' => 'completed',
            'output' => [['content' => [['type' => 'output_text', 'text' => json_encode(['task_ids' => [$blocked->id], 'note' => 'Try this tomorrow.'])]]]],
        ])]);

        $this->postJson('/api/v1/planning-days/2026-10-11/draft', [
            'client_request_id' => (string) Str::uuid(),
        ])->assertStatus(502)->assertJsonPath('message', 'The AI suggested a task Planner cannot schedule. Try again.');

        $this->assertDatabaseCount('scheduled_tasks', 0);
        $this->assertDatabaseHas('ai_planning_requests', ['status' => 'failed']);
    }

    public function test_reviewed_draft_publishes_only_selected_tasks_and_completes_the_plan(): void
    {
        $first = Task::factory()->create();
        $second = Task::factory()->create();
        Http::fake(['api.openai.com/*' => Http::response([
            'status' => 'completed',
            'output' => [['content' => [['type' => 'output_text', 'text' => json_encode([
                'task_ids' => [$first->id, $second->id],
                'note' => 'There is room for a gentle start tomorrow.',
            ])]]]],
        ])]);

        $requestId = (string) Str::uuid();
        $this->postJson('/api/v1/planning-days/2026-10-11/draft', ['client_request_id' => $requestId])
            ->assertOk();
        $this->getJson('/api/v1/planning-days/2026-10-11/plan')->assertOk()
            ->assertJsonPath('data.published', false)
            ->assertJsonPath('data.draft.client_request_id', $requestId);

        $this->postJson('/api/v1/planning-days/2026-10-11/plan', [
            'client_request_id' => $requestId,
            'task_ids' => [$first->id],
        ])->assertOk()->assertJsonPath('data.published', true)
            ->assertJsonPath('data.note', 'There is room for a gentle start tomorrow.');

        $this->assertDatabaseHas('scheduled_tasks', ['task_id' => $first->id, 'source' => 'planner']);
        $this->assertDatabaseMissing('scheduled_tasks', ['task_id' => $second->id]);
        $this->assertDatabaseHas('planning_cycles', ['plan_date' => '2026-10-11']);
        $this->assertNotNull(DB::table('planning_cycles')->where('plan_date', '2026-10-11')->value('plan_completed_at'));
        Http::assertSentCount(1);
    }

    public function test_task_changed_after_draft_cannot_be_published(): void
    {
        $task = Task::factory()->create();
        Http::fake(['api.openai.com/*' => Http::response([
            'status' => 'completed',
            'output' => [['content' => [['type' => 'output_text', 'text' => json_encode(['task_ids' => [$task->id], 'note' => ''])]]]],
        ])]);

        $requestId = (string) Str::uuid();
        $this->postJson('/api/v1/planning-days/2026-10-11/draft', ['client_request_id' => $requestId])->assertOk();
        $dependency = Task::factory()->create();
        $task->dependencies()->attach($dependency);

        $this->postJson('/api/v1/planning-days/2026-10-11/plan', [
            'client_request_id' => $requestId,
            'task_ids' => [$task->id],
        ])->assertUnprocessable();

        $this->assertDatabaseCount('scheduled_tasks', 0);
    }

    public function test_a_new_deadline_task_invalidates_an_older_unpublished_draft(): void
    {
        $task = Task::factory()->create();
        Http::fake(['api.openai.com/*' => Http::response([
            'status' => 'completed',
            'output' => [['content' => [['type' => 'output_text', 'text' => json_encode(['task_ids' => [$task->id], 'note' => ''])]]]],
        ])]);

        $requestId = (string) Str::uuid();
        $this->postJson('/api/v1/planning-days/2026-10-11/draft', ['client_request_id' => $requestId])->assertOk();
        Task::factory()->create(['deadline_on' => '2026-10-11', 'deadline_is_hard' => true]);

        $this->getJson('/api/v1/planning-days/2026-10-11/plan')->assertOk()->assertJsonPath('data.draft', null);
        $this->postJson('/api/v1/planning-days/2026-10-11/plan', [
            'client_request_id' => $requestId,
            'task_ids' => [$task->id],
        ])->assertUnprocessable()->assertJsonPath('errors.client_request_id.0', 'Tomorrow changed. Get a fresh draft.');

        $this->assertDatabaseCount('scheduled_tasks', 0);
    }

    public function test_changing_setup_invalidates_an_unpublished_draft(): void
    {
        $task = Task::factory()->create();
        Http::fake(['api.openai.com/*' => Http::response([
            'status' => 'completed',
            'output' => [['content' => [['type' => 'output_text', 'text' => json_encode(['task_ids' => [$task->id], 'note' => ''])]]]],
        ])]);

        $requestId = (string) Str::uuid();
        $this->postJson('/api/v1/planning-days/2026-10-11/draft', ['client_request_id' => $requestId])->assertOk();
        $this->putJson('/api/v1/planning-days/2026-10-11/setup', [
            'energy_level' => 'low',
            'day_off' => false,
            'dinner_enabled' => false,
            'dinner_dish' => null,
            'manual_task_ids' => [],
            'task_review' => [],
        ])->assertOk();

        $this->getJson('/api/v1/planning-days/2026-10-11/plan')->assertOk()
            ->assertJsonPath('data.draft', null);
        $this->postJson('/api/v1/planning-days/2026-10-11/plan', [
            'client_request_id' => $requestId,
            'task_ids' => [$task->id],
        ])->assertUnprocessable();
    }

    public function test_task_needing_research_cannot_be_published_without_it(): void
    {
        $task = Task::factory()->create(['special_instructions' => 'Check the weather.']);
        Http::fake(['api.openai.com/*' => Http::response([
            'status' => 'completed',
            'output' => [['content' => [['type' => 'output_text', 'text' => json_encode(['task_ids' => [$task->id], 'note' => ''])]]]],
        ])]);

        $requestId = (string) Str::uuid();
        $this->postJson('/api/v1/planning-days/2026-10-11/draft', ['client_request_id' => $requestId])
            ->assertOk()->assertJsonPath('data.needs_checks_task_ids.0', $task->id);
        $this->postJson('/api/v1/planning-days/2026-10-11/plan', [
            'client_request_id' => $requestId,
            'task_ids' => [$task->id],
        ])->assertUnprocessable();

        $this->assertDatabaseCount('scheduled_tasks', 0);
    }

    public function test_day_off_can_be_saved_without_an_ai_call(): void
    {
        PlanningDayInput::query()->where('plan_date', '2026-10-11')->update(['day_off' => true]);
        Http::fake();

        $this->postJson('/api/v1/planning-days/2026-10-11/plan', ['task_ids' => []])
            ->assertOk()->assertJsonPath('data.published', true);

        Http::assertNothingSent();
    }

    public function test_moving_a_planned_task_to_manual_keeps_it_scheduled(): void
    {
        $task = Task::factory()->create();
        Http::fake(['api.openai.com/*' => Http::response([
            'status' => 'completed',
            'output' => [['content' => [['type' => 'output_text', 'text' => json_encode(['task_ids' => [$task->id], 'note' => ''])]]]],
        ])]);

        $requestId = (string) Str::uuid();
        $this->postJson('/api/v1/planning-days/2026-10-11/draft', ['client_request_id' => $requestId])->assertOk();
        $this->postJson('/api/v1/planning-days/2026-10-11/plan', [
            'client_request_id' => $requestId,
            'task_ids' => [$task->id],
        ])->assertOk();

        $this->putJson('/api/v1/planning-days/2026-10-11/setup', [
            'energy_level' => 'standard',
            'day_off' => false,
            'dinner_enabled' => false,
            'dinner_dish' => null,
            'manual_task_ids' => [$task->id],
            'task_review' => [],
        ])->assertOk();

        $this->assertDatabaseHas('scheduled_tasks', ['task_id' => $task->id, 'source' => 'manual']);
        $this->assertDatabaseCount('scheduled_tasks', 1);
        $this->assertNull(DB::table('planning_cycles')->where('plan_date', '2026-10-11')->value('plan_completed_at'));
    }
}

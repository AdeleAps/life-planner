<?php

namespace Tests\Feature;

use App\Models\PlanningFocus;
use App\Services\PlanningBriefBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanningFocusTest extends TestCase
{
    use RefreshDatabase;

    public function test_review_prompts_once_on_the_eve_of_a_new_week_and_month(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-01-31 20:00:00', 'Europe/Riga'));

        try {
            $this->getJson('/api/v1/focuses?date=2027-02-01')->assertOk()
                ->assertJsonPath('week.focus', null)
                ->assertJsonPath('month.focus', null);

            $prompts = $this->postJson('/api/v1/focuses/tomorrow-prompts')->assertOk()->json('prompts');

            $this->assertSame(['week', 'month'], array_column($prompts, 'kind'));
            $this->assertSame(['2027-02-01', '2027-02-01'], array_column($prompts, 'starts_on'));
            $this->assertDatabaseCount('planning_foci', 2);
            $this->postJson('/api/v1/focuses/tomorrow-prompts')->assertOk()->assertJsonPath('prompts', []);

            $this->putJson('/api/v1/focuses/week/2027-02-01', ['focus' => 'Finish the garden'])->assertOk()
                ->assertJsonPath('focus', 'Finish the garden');
            $this->putJson('/api/v1/focuses/month/2027-02-01', ['focus' => 'Spend more time outdoors'])->assertOk();
            $this->getJson('/api/v1/focuses?date=2027-02-02')->assertOk()
                ->assertJsonPath('week.focus', 'Finish the garden')
                ->assertJsonPath('month.focus', 'Spend more time outdoors');

            $brief = app(PlanningBriefBuilder::class)->build(CarbonImmutable::parse('2027-02-02', 'Europe/Riga'));
            $this->assertSame(['week', 'month'], array_column($brief['focuses'], 'kind'));
            $this->assertSame('Finish the garden', $brief['focuses'][0]['focus']);

            $this->putJson('/api/v1/focuses/week/2027-02-01', ['focus' => null])->assertOk()
                ->assertJsonPath('focus', null);
            $this->assertCount(1, app(PlanningBriefBuilder::class)->build(CarbonImmutable::parse('2027-02-02', 'Europe/Riga'))['focuses']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_skipped_focus_stays_optional_and_does_not_prompt_again(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-11 18:00:00', 'Europe/Riga'));

        try {
            $this->postJson('/api/v1/focuses/tomorrow-prompts')->assertOk()
                ->assertJsonPath('prompts.0.kind', 'week')
                ->assertJsonPath('prompts.0.starts_on', '2026-10-12');
            $this->postJson('/api/v1/focuses/tomorrow-prompts')->assertOk()->assertJsonPath('prompts', []);
            $this->getJson('/api/v1/focuses?date=2026-10-12')->assertOk()->assertJsonPath('week.focus', null);
            $this->assertNull(PlanningFocus::query()->firstOrFail()->focus);

            CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-18 20:00:00', 'Europe/Riga'));
            $this->postJson('/api/v1/focuses/tomorrow-prompts')->assertOk()
                ->assertJsonPath('prompts.0.starts_on', '2026-10-19');
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}

<?php

namespace Tests\Feature;

use App\Models\PlanningCycle;
use App\Models\PlanningDayInput;
use App\Services\WebPushSender;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EveningReviewScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_evening_trigger_requests_review_once_for_the_next_riga_day(): void
    {
        $tomorrow = CarbonImmutable::now('Europe/Riga')->addDay()->toDateString();
        $this->mock(WebPushSender::class)
            ->shouldReceive('sendReviewReminder')
            ->once()
            ->with($tomorrow)
            ->andReturn($this->sentReminder());

        $this->artisan('planner:queue-evening-review')->assertExitCode(0);
        $this->assertDatabaseHas('planning_cycles', [
            'plan_date' => $tomorrow,
            'plan_completed_at' => null,
        ]);
        $this->assertNotNull(PlanningCycle::query()->sole()->review_requested_at);
        $this->assertNotNull(PlanningCycle::query()->sole()->review_notification_sent_at);

        $this->artisan('planner:queue-evening-review')->assertExitCode(0);
        $this->assertDatabaseCount('planning_cycles', 1);
    }

    public function test_early_completed_plan_skips_the_evening_review(): void
    {
        $tomorrow = CarbonImmutable::now('Europe/Riga')->addDay()->toDateString();
        $this->mock(WebPushSender::class)->shouldNotReceive('sendReviewReminder');
        PlanningCycle::factory()->create([
            'plan_date' => $tomorrow,
            'plan_completed_at' => now(),
        ]);

        $this->artisan('planner:queue-evening-review')->assertExitCode(0);

        $this->assertDatabaseHas('planning_cycles', [
            'plan_date' => $tomorrow,
            'review_requested_at' => null,
            'review_notification_sent_at' => null,
        ]);
    }

    public function test_saving_setup_alone_does_not_skip_the_evening_review(): void
    {
        $tomorrow = CarbonImmutable::now('Europe/Riga')->addDay()->toDateString();
        $this->mock(WebPushSender::class)
            ->shouldReceive('sendReviewReminder')
            ->once()
            ->with($tomorrow)
            ->andReturn($this->sentReminder());
        PlanningDayInput::query()->create([
            'plan_date' => $tomorrow,
            'energy_level' => 'standard',
            'day_off' => false,
            'dinner_enabled' => false,
            'revision' => 1,
        ]);

        $this->artisan('planner:queue-evening-review')->assertExitCode(0);

        $this->assertNotNull(PlanningCycle::query()->sole()->review_requested_at);
        $this->assertNotNull(PlanningCycle::query()->sole()->review_notification_sent_at);
    }

    public function test_failed_delivery_can_be_retried_without_a_duplicate_review_request(): void
    {
        $tomorrow = CarbonImmutable::now('Europe/Riga')->addDay()->toDateString();
        $this->mock(WebPushSender::class)
            ->shouldReceive('sendReviewReminder')
            ->twice()
            ->with($tomorrow)
            ->andReturn(
                ['sent' => 0, 'already_sent' => 0, 'failed' => 1, 'expired' => 0],
                $this->sentReminder(),
            );

        $this->artisan('planner:queue-evening-review')->assertExitCode(1);
        $firstRequest = PlanningCycle::query()->sole()->review_requested_at;
        $this->assertNull(PlanningCycle::query()->sole()->review_notification_sent_at);

        $this->artisan('planner:queue-evening-review')->assertExitCode(0);
        $this->assertEquals($firstRequest, PlanningCycle::query()->sole()->review_requested_at);
        $this->assertNotNull(PlanningCycle::query()->sole()->review_notification_sent_at);
    }

    /**
     * @return array{sent: int, already_sent: int, failed: int, expired: int}
     */
    private function sentReminder(): array
    {
        return ['sent' => 1, 'already_sent' => 0, 'failed' => 0, 'expired' => 0];
    }
}

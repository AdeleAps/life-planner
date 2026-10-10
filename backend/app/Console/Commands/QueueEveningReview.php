<?php

namespace App\Console\Commands;

use App\Models\PlanningCycle;
use App\Services\WebPushSender;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

#[Signature('planner:queue-evening-review')]
#[Description('Request the evening review and send a reminder unless tomorrow was already planned')]
class QueueEveningReview extends Command
{
    public function handle(WebPushSender $sender): int
    {
        $planDate = CarbonImmutable::now('Europe/Riga')->addDay()->toDateString();

        $cycle = DB::transaction(function () use ($planDate): ?PlanningCycle {
            $cycle = PlanningCycle::query()
                ->where('plan_date', $planDate)
                ->lockForUpdate()
                ->first();

            if ($cycle === null) {
                return PlanningCycle::query()->create([
                    'plan_date' => $planDate,
                    'review_requested_at' => now(),
                ]);
            }

            if ($cycle->plan_completed_at !== null || $cycle->review_notification_sent_at !== null) {
                return null;
            }

            if ($cycle->review_requested_at === null) {
                $cycle->review_requested_at = now();
                $cycle->save();
            }

            return $cycle;
        }, 3);

        if ($cycle === null) {
            $this->components->info("Evening review already handled for {$planDate}.");

            return self::SUCCESS;
        }

        try {
            $result = $sender->sendReviewReminder($planDate);
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($result['failed'] > 0) {
            $this->components->error("Reminder failed for {$result['failed']} subscribed phone(s).");

            return self::FAILURE;
        }

        if ($result['sent'] + $result['already_sent'] === 0) {
            $this->components->warn('No subscribed phone received the reminder.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($cycle): void {
            $current = PlanningCycle::query()->lockForUpdate()->findOrFail($cycle->id);

            if ($current->plan_completed_at === null && $current->review_notification_sent_at === null) {
                $current->review_notification_sent_at = now();
                $current->save();
            }
        }, 3);

        $this->components->info("Evening review reminder sent for {$planDate}.");

        return self::SUCCESS;
    }
}

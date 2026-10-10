<?php

namespace App\Console\Commands;

use App\Services\WebPushSender;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('planner:send-test-push')]
#[Description('Send a test planning reminder to registered iPhones')]
class SendTestPush extends Command
{
    public function handle(WebPushSender $sender): int
    {
        try {
            $result = $sender->sendReviewReminder();
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Sent: {$result['sent']}; failed: {$result['failed']}; expired: {$result['expired']}.");

        return $result['sent'] > 0 ? self::SUCCESS : self::FAILURE;
    }
}

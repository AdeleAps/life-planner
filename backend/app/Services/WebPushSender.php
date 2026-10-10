<?php

namespace App\Services;

use App\Models\PushSubscription;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use RuntimeException;
use Throwable;

class WebPushSender
{
    /**
     * @return array{sent: int, already_sent: int, failed: int, expired: int}
     */
    public function sendReviewReminder(?string $planDate = null): array
    {
        $publicKey = config('services.web_push.public_key');
        $privateKey = config('services.web_push.private_key');
        $subject = config('services.web_push.subject');

        if (! $publicKey || ! $privateKey || ! $subject) {
            throw new RuntimeException('Web Push keys are not configured.');
        }

        $webPush = new WebPush(['VAPID' => [
            'subject' => $subject,
            'publicKey' => $publicKey,
            'privateKey' => $privateKey,
        ]]);

        $payload = json_encode([
            'title' => 'Time to plan',
            'body' => 'Set up tomorrow in Planner.',
            'url' => '/?setup=tomorrow',
        ], JSON_THROW_ON_ERROR);

        $counts = ['sent' => 0, 'already_sent' => 0, 'failed' => 0, 'expired' => 0];

        foreach (PushSubscription::query()->cursor() as $savedSubscription) {
            if ($planDate !== null && $savedSubscription->last_review_plan_date?->toDateString() === $planDate) {
                $counts['already_sent']++;

                continue;
            }

            try {
                $subscription = Subscription::create([
                    'endpoint' => $savedSubscription->endpoint,
                    'keys' => [
                        'p256dh' => $savedSubscription->public_key,
                        'auth' => $savedSubscription->auth_token,
                    ],
                    'contentEncoding' => 'aes128gcm',
                ]);

                $report = $webPush->sendOneNotification($subscription, $payload, [
                    'TTL' => 12 * 60 * 60,
                    'urgency' => 'normal',
                    'contentType' => 'application/json',
                ]);

                if ($report->isSuccess()) {
                    $counts['sent']++;

                    if ($planDate !== null) {
                        $savedSubscription->last_review_plan_date = $planDate;
                        $savedSubscription->save();
                    }
                } elseif ($report->isSubscriptionExpired()) {
                    $savedSubscription->delete();
                    $counts['expired']++;
                } else {
                    $counts['failed']++;
                    Log::warning('Web Push reminder failed.', [
                        'subscription_id' => $savedSubscription->id,
                        'status' => $report->getResponse()?->getStatusCode(),
                    ]);
                }
            } catch (Throwable $exception) {
                $counts['failed']++;
                Log::warning('Web Push reminder could not be sent.', [
                    'subscription_id' => $savedSubscription->id,
                    'exception' => $exception::class,
                ]);
            }
        }

        return $counts;
    }
}

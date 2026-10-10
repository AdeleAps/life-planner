<?php

namespace App\Console\Commands;

use App\Services\AdvanceOfferPlanner;
use App\Services\WebPushSender;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

#[Signature('planner:refresh-advance-offers')]
#[Description('Check advance-notice tasks and notify the user about new proposed dates')]
class RefreshAdvanceOffers extends Command
{
    public function handle(AdvanceOfferPlanner $planner, WebPushSender $sender): int
    {
        $offers = $planner->prepare();

        foreach ($offers as $offer) {
            $noticeConflict = $offer['status'] === 'unavailable' && $offer['deadline_on'] !== null
                && CarbonImmutable::now('Europe/Riga')->addDays($offer['advance_notice_days'])->toDateString() > $offer['deadline_on'];

            if (($offer['status'] !== 'offered' && ! $noticeConflict)
                || DB::table('advance_offers')->where('id', $offer['id'])->whereNotNull('notified_at')->exists()) {
                continue;
            }

            try {
                $result = $sender->sendAdvanceOfferReminder($offer['title'], $noticeConflict);
            } catch (RuntimeException $exception) {
                $this->components->warn($exception->getMessage());

                continue;
            }

            if ($result['sent'] > 0 && $result['failed'] === 0) {
                DB::table('advance_offers')->where('id', $offer['id'])->update([
                    'notified_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        $this->components->info('Advance tasks checked.');

        return self::SUCCESS;
    }
}

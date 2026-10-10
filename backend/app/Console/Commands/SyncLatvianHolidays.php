<?php

namespace App\Console\Commands;

use App\Services\LatvianHolidaySync;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('planner:sync-latvian-holidays {year? : Year to sync; defaults to this year and next year} {--force : Refresh an already synced year}')]
#[Description('Import Latvian public holidays into the Planner calendar')]
class SyncLatvianHolidays extends Command
{
    public function handle(LatvianHolidaySync $sync): int
    {
        $year = $this->argument('year');
        $currentYear = CarbonImmutable::now('Europe/Riga')->year;
        $years = $year === null ? [$currentYear, $currentYear + 1] : [(int) $year];

        foreach ($years as $calendarYear) {
            if ($calendarYear < 2020 || $calendarYear > 2100) {
                $this->components->error('Use a year between 2020 and 2100.');

                return self::FAILURE;
            }

            try {
                $count = $sync->syncYear($calendarYear, (bool) $this->option('force'));
                $this->components->info("{$calendarYear}: {$count} public holiday dates available.");
            } catch (Throwable $exception) {
                $this->components->error("{$calendarYear}: {$exception->getMessage()}");

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}

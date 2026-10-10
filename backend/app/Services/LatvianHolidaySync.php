<?php

namespace App\Services;

use App\Models\DayOffPeriod;
use App\Models\HolidaySync;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class LatvianHolidaySync
{
    private const PROVIDER = 'nager-v4';

    public function isSynced(int $year): bool
    {
        return HolidaySync::query()->where('year', $year)->exists();
    }

    public function ensureYear(int $year): bool
    {
        if ($this->isSynced($year)) {
            return true;
        }

        try {
            $this->syncYear($year);

            return true;
        } catch (Throwable $exception) {
            Log::warning('Latvian holiday sync failed', ['year' => $year, 'message' => $exception->getMessage()]);

            return false;
        }
    }

    public function syncYear(int $year, bool $force = false): int
    {
        if (! $force && $this->isSynced($year)) {
            return DayOffPeriod::query()->where('source', 'national')->whereYear('start_on', $year)->count();
        }

        $response = Http::acceptJson()->timeout(15)->retry(2, 300)
            ->get("https://nagerholidays.com/api/v4/Holidays/LV/{$year}");

        if (! $response->successful() || ! is_array($response->json())) {
            throw new RuntimeException("The holiday source did not return a valid list for {$year}.");
        }

        $holidaysByDate = [];

        foreach ($response->json() as $holiday) {
            if (! is_array($holiday)
                || ($holiday['countryCode'] ?? null) !== 'LV'
                || ($holiday['nationalHoliday'] ?? null) !== true
                || ! in_array('Public', $holiday['holidayTypes'] ?? [], true)) {
                continue;
            }

            $date = $holiday['date'] ?? null;
            $name = $holiday['name'] ?? null;

            if (! is_string($date) || ! preg_match('/^'.preg_quote((string) $year, '/').'-\d{2}-\d{2}$/', $date)
                || ! is_string($name) || trim($name) === '') {
                continue;
            }

            $holidaysByDate[$date][] = trim($name);
        }

        if ($holidaysByDate === []) {
            throw new RuntimeException("The holiday source returned no Latvian public holidays for {$year}.");
        }

        // Latvian law grants a following workday when 4 May or 18 November falls on a weekend.
        foreach (['05-04', '11-18'] as $monthDay) {
            $holidayDate = CarbonImmutable::parse("{$year}-{$monthDay}", 'Europe/Riga');

            if ($holidayDate->isWeekend()) {
                $observedDate = $holidayDate->addDays($holidayDate->isSaturday() ? 2 : 1)->toDateString();
                $holidaysByDate[$observedDate][] = "Day off after {$holidayDate->format('j F')}";
            }
        }

        DB::transaction(function () use ($year, $holidaysByDate): void {
            DayOffPeriod::query()->where('source', 'national')->whereYear('start_on', $year)->delete();

            foreach ($holidaysByDate as $date => $names) {
                DayOffPeriod::query()->create([
                    'source' => 'national',
                    'title' => implode(' / ', array_unique($names)),
                    'start_on' => $date,
                    'end_on' => $date,
                    'source_key' => "LV-{$date}",
                ]);
            }

            HolidaySync::query()->updateOrCreate(['year' => $year], [
                'provider' => self::PROVIDER,
                'synced_at' => now(),
            ]);
        });

        return count($holidaysByDate);
    }
}

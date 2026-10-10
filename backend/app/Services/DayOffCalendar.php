<?php

namespace App\Services;

use App\Models\DayOffPeriod;
use App\Models\HolidaySync;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class DayOffCalendar
{
    public function isGoonModeOn(string $date): bool
    {
        return DayOffPeriod::query()->where('source', 'personal')->where('goon_mode', true)
            ->where('start_on', '<=', $date)->where('end_on', '>=', $date)->exists();
    }

    /** @return Collection<string, array<string, mixed>> */
    public function days(CarbonImmutable $firstDate, CarbonImmutable $lastDate): Collection
    {
        $periods = DayOffPeriod::query()
            ->where('start_on', '<=', $lastDate->toDateString())
            ->where('end_on', '>=', $firstDate->toDateString())
            ->orderBy('start_on')->get();
        $syncedYears = HolidaySync::query()
            ->whereBetween('year', [$firstDate->year, $lastDate->year])
            ->pluck('year')->all();
        $days = collect();

        for ($date = $firstDate; $date->lessThanOrEqualTo($lastDate); $date = $date->addDay()) {
            $key = $date->toDateString();
            $coveringPeriods = $periods->filter(fn (DayOffPeriod $period): bool => $period->start_on->toDateString() <= $key
                && $period->end_on->toDateString() >= $key);
            $nationalHolidays = $coveringPeriods->where('source', 'national');
            $personalPeriods = $coveringPeriods->where('source', 'personal');
            $holidayYearSynced = in_array($date->year, $syncedYears, true);
            $isDayOff = $date->isWeekend() || $nationalHolidays->isNotEmpty() || $personalPeriods->isNotEmpty();
            $goonMode = $personalPeriods->contains(fn (DayOffPeriod $period): bool => $period->goon_mode);

            $days->put($key, [
                'date' => $key,
                'is_weekend' => $date->isWeekend(),
                'is_day_off' => $isDayOff,
                'goon_mode' => $goonMode,
                'day_type' => $isDayOff ? 'day_off' : ($holidayYearSynced ? 'workday' : 'unverified'),
                'holiday_year_synced' => $holidayYearSynced,
                'holiday_names' => $nationalHolidays->pluck('title')->all(),
                'personal_days_off' => $personalPeriods->map(fn (DayOffPeriod $period): array => [
                    'id' => $period->id,
                    'title' => $period->title,
                    'start_on' => $period->start_on->toDateString(),
                    'end_on' => $period->end_on->toDateString(),
                    'goon_mode' => $period->goon_mode,
                ])->values()->all(),
            ]);
        }

        return $days;
    }
}

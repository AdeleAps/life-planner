<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DayOffPeriodResource;
use App\Models\DayOffPeriod;
use App\Services\DayOffCalendar;
use App\Services\LatvianHolidaySync;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CalendarController extends Controller
{
    public function bootstrap(LatvianHolidaySync $sync): JsonResponse
    {
        $year = CarbonImmutable::now('Europe/Riga')->year;

        return response()->json([
            'years' => [
                $year => $sync->ensureYear($year),
                $year + 1 => $sync->ensureYear($year + 1),
            ],
        ]);
    }

    public function index(Request $request, LatvianHolidaySync $sync, DayOffCalendar $calendar): JsonResponse
    {
        $month = $request->query('month', CarbonImmutable::now('Europe/Riga')->format('Y-m'));
        Validator::make(['month' => $month], ['month' => ['required', 'regex:/^(20\d{2}|2100)-(0[1-9]|1[0-2])$/']])->validate();

        $firstDate = CarbonImmutable::parse("{$month}-01", 'Europe/Riga');
        $lastDate = $firstDate->endOfMonth();
        $holidayYearSynced = $sync->ensureYear($firstDate->year);
        $personalPeriods = DayOffPeriod::query()
            ->where('source', 'personal')
            ->where('start_on', '<=', $lastDate->toDateString())
            ->where('end_on', '>=', $firstDate->toDateString())
            ->orderBy('start_on')->get();

        return response()->json([
            'month' => $month,
            'holiday_year_synced' => $holidayYearSynced,
            'days' => $calendar->days($firstDate, $lastDate)->values()->all(),
            'personal_days_off' => DayOffPeriodResource::collection($personalPeriods)->resolve(),
        ]);
    }

    public function storeDayOff(Request $request): JsonResponse
    {
        $input = $this->validatedDayOff($request);

        $period = DayOffPeriod::query()->create([
            'source' => 'personal',
            'title' => trim($input['title'] ?? '') ?: 'Vacation',
            'start_on' => $input['start_on'],
            'end_on' => $input['end_on'],
            'source_key' => null,
            'goon_mode' => $input['goon_mode'] ?? false,
        ]);

        return (new DayOffPeriodResource($period))->response()->setStatusCode(201);
    }

    public function updateDayOff(Request $request, DayOffPeriod $dayOffPeriod): JsonResponse
    {
        abort_unless($dayOffPeriod->source === 'personal', 404);
        $input = $this->validatedDayOff($request);

        $dayOffPeriod->update([
            'title' => trim($input['title'] ?? '') ?: 'Vacation',
            'start_on' => $input['start_on'],
            'end_on' => $input['end_on'],
            'goon_mode' => $input['goon_mode'] ?? false,
        ]);

        return (new DayOffPeriodResource($dayOffPeriod))->response();
    }

    public function destroyDayOff(DayOffPeriod $dayOffPeriod): JsonResponse
    {
        abort_unless($dayOffPeriod->source === 'personal', 404);
        $dayOffPeriod->delete();

        return response()->json(['deleted' => true]);
    }

    /** @return array<string, mixed> */
    private function validatedDayOff(Request $request): array
    {
        return $request->validate([
            'title' => ['nullable', 'string', 'max:100'],
            'start_on' => ['required', 'date_format:Y-m-d'],
            'end_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_on'],
            'goon_mode' => ['sometimes', 'boolean'],
        ]);
    }
}

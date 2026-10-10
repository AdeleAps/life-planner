<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DayOffPeriodResource;
use App\Http\Resources\ScheduledTaskResource;
use App\Models\DayOffPeriod;
use App\Models\ScheduledTask;
use App\Services\DayOffCalendar;
use App\Services\LatvianHolidaySync;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
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
        $tomorrowDate = CarbonImmutable::now('Europe/Riga')->addDay()->toDateString();
        $scheduledTasksByDate = ScheduledTask::query()
            ->whereBetween('plan_date', [$firstDate->toDateString(), $lastDate->toDateString()])
            ->where(function (Builder $query) use ($tomorrowDate): void {
                $query->where('plan_date', '<=', $tomorrowDate)
                    ->orWhereIn('source', ['manual', 'dinner_groceries', 'dinner_cooking', 'advance_accepted']);
            })
            ->with('task:id,title,type,recurrence_type,is_multi_session,lifecycle_state,revision')
            ->orderBy('id')
            ->get()
            ->groupBy('plan_date');
        $days = $calendar->days($firstDate, $lastDate)
            ->map(function (array $day) use ($scheduledTasksByDate): array {
                $day['scheduled_tasks'] = ScheduledTaskResource::collection(
                    $scheduledTasksByDate->get($day['date'], collect())
                )->resolve();

                return $day;
            });

        return response()->json([
            'month' => $month,
            'holiday_year_synced' => $holidayYearSynced,
            'days' => $days->values()->all(),
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

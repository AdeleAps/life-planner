<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PlanningFocus;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PlanningFocusController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $date = $request->query('date', CarbonImmutable::now('Europe/Riga')->addDay()->toDateString());
        Validator::make(['date' => $date], ['date' => ['required', 'date_format:Y-m-d']])->validate();
        $targetDate = CarbonImmutable::parse($date, 'Europe/Riga');

        return response()->json([
            'week' => $this->periodSummary('week', PlanningFocus::periodStart('week', $targetDate)),
            'month' => $this->periodSummary('month', PlanningFocus::periodStart('month', $targetDate)),
        ]);
    }

    public function claimTomorrowPrompts(): JsonResponse
    {
        $tomorrow = CarbonImmutable::now('Europe/Riga')->addDay();
        $dueKinds = [];

        if ($tomorrow->isMonday()) {
            $dueKinds[] = 'week';
        }

        if ($tomorrow->day === 1) {
            $dueKinds[] = 'month';
        }

        $prompts = DB::transaction(function () use ($dueKinds, $tomorrow): array {
            $prompts = [];

            foreach ($dueKinds as $kind) {
                $start = PlanningFocus::periodStart($kind, $tomorrow);
                $focus = PlanningFocus::query()->firstOrCreate([
                    'kind' => $kind,
                    'starts_on' => $start->toDateString(),
                ]);

                if ($focus->focus !== null || $focus->prompted_at !== null) {
                    continue;
                }

                $focus->update(['prompted_at' => now()]);
                $prompts[] = $this->periodSummary($kind, $start, $focus);
            }

            return $prompts;
        });

        return response()->json(['prompts' => $prompts]);
    }

    public function update(Request $request, string $kind, string $startsOn): JsonResponse
    {
        Validator::make(['kind' => $kind, 'starts_on' => $startsOn], [
            'kind' => ['required', Rule::in(['week', 'month'])],
            'starts_on' => ['required', 'date_format:Y-m-d'],
        ])->validate();

        $start = CarbonImmutable::parse($startsOn, 'Europe/Riga');

        if (PlanningFocus::periodStart($kind, $start)->toDateString() !== $startsOn) {
            throw ValidationException::withMessages(['starts_on' => 'Choose the first day of the period.']);
        }

        $input = $request->validate(['focus' => ['present', 'nullable', 'string', 'max:500']]);
        $text = trim($input['focus'] ?? '');
        $focus = PlanningFocus::query()->firstOrCreate(['kind' => $kind, 'starts_on' => $startsOn]);
        $focus->update(['focus' => $text === '' ? null : $text, 'prompted_at' => $focus->prompted_at ?? now()]);

        return response()->json($this->periodSummary($kind, $start, $focus));
    }

    /** @return array<string, mixed> */
    private function periodSummary(string $kind, CarbonImmutable $start, ?PlanningFocus $focus = null): array
    {
        $focus ??= PlanningFocus::query()->where('kind', $kind)->where('starts_on', $start->toDateString())->first();

        return [
            'kind' => $kind,
            'starts_on' => $start->toDateString(),
            'ends_on' => $kind === 'week' ? $start->addDays(6)->toDateString() : $start->endOfMonth()->toDateString(),
            'focus' => $focus?->focus,
        ];
    }
}

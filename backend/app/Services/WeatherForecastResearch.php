<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WeatherForecastResearch
{
    /** @param array<string, mixed> $task */
    public function supports(array $task): bool
    {
        $instructions = (string) ($task['special_instructions'] ?? '');
        $context = implode(' ', [(string) ($task['title'] ?? ''), (string) ($task['description'] ?? ''), $instructions]);

        return preg_match('/\b(weather|forecast)\b/iu', $instructions) === 1
            && preg_match('/\b(hike|hiking|walking|walk)\b/iu', $context) === 1
            && preg_match('/\b(?:near|in|around|at)\s+(?!(?:Riga|Latvia)\b)\p{Lu}[\p{L}-]+/u', $context) !== 1
            && preg_match('/\b(parking|booking|reservation|tickets?|opening hours|price|traffic)\b/iu', $instructions) !== 1;
    }

    /** @param  array<string, mixed>  $task */
    public function hasAdditionalResearch(array $task): bool
    {
        return preg_match('/\b(also|ideas?|suggest|recommend|research|look up|find|parking|booking|reservation|tickets?|opening hours|price|traffic)\b/iu',
            (string) ($task['special_instructions'] ?? '')) === 1;
    }

    /**
     * @param  array<int, array<string, mixed>>  $dates
     * @return array<string, mixed>
     */
    public function suggest(array $dates): array
    {
        $forecast = $this->forecast();

        if ($forecast === null) {
            return $this->unknown('Could not load the Riga forecast right now.');
        }

        $days = $this->forecastDays($forecast);
        $covered = collect($dates)->filter(fn (array $date): bool => isset($days[$date['date']]));
        $suitable = $covered->filter(fn (array $date): bool => $this->isSuitable($days[$date['date']]));

        if ($suitable->isNotEmpty()) {
            $chosen = $suitable->sortBy([['notice_shortfall_days', 'asc'], ['date', 'asc']])->first();
            $day = $days[$chosen['date']];

            return [
                'status' => 'ready', 'proposed_on' => $chosen['date'],
                'summary' => sprintf('Riga looks dry enough for a hike on %s (%.0f%% rain chance, %.1f mm forecast).',
                    CarbonImmutable::parse($chosen['date'])->format('j F'), $day['rain_chance'], $day['rain_mm']),
                'sources' => [], 'evidence' => $this->evidence($chosen['date'], $day),
            ];
        }

        if ($covered->isEmpty()) {
            return $this->unknown('The Riga forecast does not reach an open day for this hike yet.');
        }

        $last = CarbonImmutable::parse($covered->max('date'))->format('j F');

        return $this->unknown($covered->count() === count($dates)
            ? "The Riga forecast does not show a dry hiking day on the open dates through {$last}."
            : "No dry hiking day appears in the Riga forecast through {$last}; later dates are not forecast yet.");
    }

    /** @return array<string, mixed> */
    public function checkDate(string $date): array
    {
        $forecast = $this->forecast();

        if ($forecast === null) {
            return $this->unknown('Could not load the Riga forecast right now.');
        }

        $day = $this->forecastDays($forecast)[$date] ?? null;

        if ($day === null) {
            return $this->unknown('The Riga forecast does not cover this date yet.');
        }

        $suitable = $this->isSuitable($day);

        return [
            'status' => $suitable ? 'ready' : 'unsuitable',
            'summary' => sprintf('Riga forecast for %s: %.0f%% rain chance and %.1f mm precipitation; %s.',
                CarbonImmutable::parse($date)->format('j F'), $day['rain_chance'], $day['rain_mm'],
                $suitable ? 'suitable for a dry hike' : 'not a dry hiking day'),
            'sources' => [], 'valid_until_on' => $date, 'evidence' => $this->evidence($date, $day),
        ];
    }

    /** @return array<string, mixed>|null */
    private function forecast(): ?array
    {
        try {
            $response = Http::acceptJson()->connectTimeout(5)->timeout(15)
                ->get('https://api.open-meteo.com/v1/forecast', [
                    'latitude' => 56.9496, 'longitude' => 24.1052,
                    'daily' => 'precipitation_probability_max,precipitation_sum,weather_code,temperature_2m_max',
                    'timezone' => 'Europe/Riga', 'forecast_days' => 16,
                ]);

            if ($response->successful() && is_array($response->json('daily'))) {
                return $response->json('daily');
            }

            Log::warning('Riga forecast unavailable.', ['http_status' => $response->status()]);
        } catch (ConnectionException $exception) {
            Log::warning('Riga forecast unavailable.', ['exception' => $exception::class]);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $forecast
     * @return array<string, array{rain_chance: float, rain_mm: float, weather_code: int, max_c: float}>
     */
    private function forecastDays(array $forecast): array
    {
        $days = [];

        foreach ($forecast['time'] ?? [] as $index => $date) {
            $chance = $forecast['precipitation_probability_max'][$index] ?? null;
            $rain = $forecast['precipitation_sum'][$index] ?? null;
            $code = $forecast['weather_code'][$index] ?? null;
            $temperature = $forecast['temperature_2m_max'][$index] ?? null;

            if (! is_string($date) || ! is_numeric($chance) || ! is_numeric($rain)
                || ! is_numeric($code) || ! is_numeric($temperature)) {
                continue;
            }

            $days[$date] = [
                'rain_chance' => (float) $chance, 'rain_mm' => (float) $rain,
                'weather_code' => (int) $code, 'max_c' => (float) $temperature,
            ];
        }

        return $days;
    }

    /** @param array{rain_chance: float, rain_mm: float, weather_code: int, max_c: float} $day */
    private function isSuitable(array $day): bool
    {
        return $day['rain_chance'] <= 35 && $day['rain_mm'] <= 0.5
            && $day['weather_code'] <= 3 && $day['max_c'] >= 5;
    }

    /**
     * @param  array{rain_chance: float, rain_mm: float, weather_code: int, max_c: float}  $day
     * @return array<string, mixed>
     */
    private function evidence(string $date, array $day): array
    {
        return ['provider' => 'Open-Meteo', 'location' => 'Riga', 'forecast_date' => $date,
            'rain_chance_percent' => $day['rain_chance'], 'precipitation_mm' => $day['rain_mm'],
            'weather_code' => $day['weather_code'], 'max_temperature_c' => $day['max_c']];
    }

    /** @return array<string, mixed> */
    private function unknown(string $summary): array
    {
        return ['status' => 'unknown', 'proposed_on' => null, 'summary' => $summary, 'sources' => []];
    }
}

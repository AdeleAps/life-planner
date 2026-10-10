<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\PlanningFocusFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['kind', 'starts_on', 'focus', 'prompted_at'])]
class PlanningFocus extends Model
{
    /** @use HasFactory<PlanningFocusFactory> */
    use HasFactory;

    public static function periodStart(string $kind, CarbonImmutable $date): CarbonImmutable
    {
        return $kind === 'week' ? $date->startOfWeek(CarbonInterface::MONDAY) : $date->startOfMonth();
    }

    public function periodEnd(): CarbonImmutable
    {
        $start = CarbonImmutable::parse($this->starts_on, 'Europe/Riga');

        return $this->kind === 'week' ? $start->addDays(6) : $start->endOfMonth();
    }

    protected function casts(): array
    {
        return ['prompted_at' => 'datetime'];
    }
}

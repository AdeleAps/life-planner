<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['plan_date', 'energy_level', 'day_off', 'dinner_enabled', 'dinner_dish', 'revision'])]
class PlanningDayInput extends Model
{
    protected function casts(): array
    {
        return [
            'day_off' => 'boolean',
            'dinner_enabled' => 'boolean',
            'revision' => 'integer',
        ];
    }
}

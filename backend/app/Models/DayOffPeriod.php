<?php

namespace App\Models;

use Database\Factories\DayOffPeriodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['source', 'title', 'start_on', 'end_on', 'source_key', 'goon_mode'])]
class DayOffPeriod extends Model
{
    /** @use HasFactory<DayOffPeriodFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['start_on' => 'date:Y-m-d', 'end_on' => 'date:Y-m-d', 'goon_mode' => 'boolean'];
    }
}

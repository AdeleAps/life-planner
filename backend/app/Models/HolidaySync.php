<?php

namespace App\Models;

use Database\Factories\HolidaySyncFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['year', 'provider', 'synced_at'])]
class HolidaySync extends Model
{
    /** @use HasFactory<HolidaySyncFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['year' => 'integer', 'synced_at' => 'datetime'];
    }
}

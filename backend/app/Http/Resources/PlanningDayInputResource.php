<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlanningDayInputResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'plan_date' => $this->plan_date,
            'energy_level' => $this->energy_level,
            'day_off' => $this->day_off,
            'dinner_enabled' => $this->dinner_enabled,
            'dinner_dish' => $this->dinner_dish,
            'revision' => $this->revision,
            'saved' => $this->exists,
        ];
    }
}

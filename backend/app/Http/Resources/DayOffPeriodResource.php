<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DayOffPeriodResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'start_on' => $this->start_on->toDateString(),
            'end_on' => $this->end_on->toDateString(),
            'goon_mode' => $this->goon_mode,
        ];
    }
}

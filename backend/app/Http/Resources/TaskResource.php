<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
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
            'description' => $this->description,
            'kind' => $this->kind,
            'type' => $this->type,
            'priority' => $this->priority,
            'energy_cost' => $this->energy_cost,
            'lifecycle_state' => $this->lifecycle_state,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

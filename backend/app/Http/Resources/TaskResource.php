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
            'recurrence_type' => $this->recurrence_type,
            'recurrence_rule' => $this->recurrence_rule,
            'is_multi_session' => $this->is_multi_session,
            'deadline_on' => $this->deadline_on?->toDateString(),
            'deadline_is_hard' => $this->deadline_is_hard,
            'lifecycle_state' => $this->lifecycle_state,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

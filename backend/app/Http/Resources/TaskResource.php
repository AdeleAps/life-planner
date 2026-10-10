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
            'dependencies' => $this->dependencies->isEmpty() ? null : $this->dependencies->map(fn ($dependency): array => [
                'id' => $dependency->id,
                'title' => $dependency->title,
                'lifecycle_state' => $dependency->lifecycle_state,
            ])->all(),
            'dependencies_complete' => $this->dependenciesComplete(),
            'item_type' => $this->item_type,
            'kind' => $this->item_type === 'project' ? 'project' : ($this->recurrence_type === 'one_time' ? 'one_off' : 'recurring'),
            'project_id' => $this->project_id,
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

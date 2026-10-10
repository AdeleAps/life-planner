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
            'special_instructions' => $this->special_instructions,
            'dependencies' => $this->dependencies->isEmpty() ? null : $this->dependencies->map(fn ($dependency): array => [
                'id' => $dependency->id,
                'title' => $dependency->title,
                'lifecycle_state' => $dependency->lifecycle_state,
            ])->all(),
            'dependencies_complete' => $this->dependenciesComplete(),
            'item_type' => $this->item_type,
            'kind' => $this->item_type === 'project' ? 'project' : ($this->recurrence_type === 'one_time' ? 'one_off' : 'recurring'),
            'project_id' => $this->project_id,
            'project_title' => $this->whenLoaded('project', fn (): ?string => $this->project?->title),
            'steps_count' => $this->item_type === 'project' ? $this->steps_count : null,
            'type' => $this->type,
            'priority' => $this->priority,
            'energy_cost' => $this->energy_cost,
            'recurrence_type' => $this->recurrence_type,
            'recurrence_rule' => $this->recurrence_rule,
            'is_multi_session' => $this->is_multi_session,
            'advance_notice_days' => $this->advance_notice_days,
            'day_preference' => $this->day_preference,
            'day_preference_is_hard' => $this->day_preference_is_hard,
            'deadline_on' => $this->deadline_on?->toDateString(),
            'deadline_is_hard' => $this->deadline_is_hard,
            'lifecycle_state' => $this->lifecycle_state,
            'revision' => $this->revision,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

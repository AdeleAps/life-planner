<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScheduledTaskResource extends JsonResource
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
            'plan_date' => $this->plan_date,
            'status' => $this->status,
            'source' => $this->source,
            'task' => [
                'id' => $this->task->id,
                'title' => $this->task->title,
                'type' => $this->task->type,
                'recurrence_type' => $this->task->recurrence_type,
                'is_multi_session' => $this->task->is_multi_session,
                'lifecycle_state' => $this->task->lifecycle_state,
                'revision' => $this->task->revision,
            ],
        ];
    }
}

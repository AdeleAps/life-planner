<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class ScheduledTaskResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $research = null;

        if (in_array($this->source, ['planner', 'advance_accepted'], true)) {
            $saved = DB::table('task_research_checks')
                ->where('task_id', $this->task_id)
                ->where('plan_date', $this->plan_date)
                ->whereIn('status', $this->source === 'advance_accepted' ? ['completed', 'failed'] : ['completed'])
                ->orderByDesc('id')
                ->value('result');
            $result = $saved === null ? null : json_decode($saved, true, 512, JSON_THROW_ON_ERROR);
            $research = $this->source === 'advance_accepted' ? $result
                : (($result['status'] ?? null) === 'ready' ? $result : null);

            if ($research !== null) {
                unset($research['cache_key']);
            }

            if ($research === null && $this->source === 'advance_accepted') {
                $saved = DB::table('advance_offers')->where('task_id', $this->task_id)
                    ->where('proposed_on', $this->plan_date)->where('status', 'accepted')
                    ->orderByDesc('id')->value('research_result');
                $result = $saved === null ? null : json_decode($saved, true, 512, JSON_THROW_ON_ERROR);
                $research = ($result['status'] ?? null) === 'ready' ? $result : null;
            }
        }

        return [
            'id' => $this->id,
            'plan_date' => $this->plan_date,
            'status' => $this->status,
            'source' => $this->source,
            'research_result' => $research,
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

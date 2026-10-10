<?php

namespace App\Models;

use Database\Factories\PlanningCycleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['plan_date', 'review_requested_at', 'review_notification_sent_at', 'plan_completed_at'])]
class PlanningCycle extends Model
{
    /** @use HasFactory<PlanningCycleFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'review_requested_at' => 'datetime',
            'review_notification_sent_at' => 'datetime',
            'plan_completed_at' => 'datetime',
        ];
    }
}

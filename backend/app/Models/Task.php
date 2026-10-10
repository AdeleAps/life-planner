<?php

namespace App\Models;

use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'client_request_id',
    'title',
    'description',
    'special_instructions',
    'source',
    'item_type',
    'project_id',
    'type',
    'priority',
    'energy_cost',
    'recurrence_type',
    'recurrence_rule',
    'is_multi_session',
    'advance_notice_days',
    'day_preference',
    'day_preference_is_hard',
    'deadline_on',
    'deadline_is_hard',
    'lifecycle_state',
    'revision',
])]
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    public function project(): BelongsTo
    {
        return $this->belongsTo(self::class, 'project_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(self::class, 'project_id');
    }

    public function dependencies(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'task_dependencies', 'task_id', 'depends_on_task_id');
    }

    public function dependenciesComplete(): bool
    {
        return $this->dependencies->every(fn (self $dependency): bool => $dependency->lifecycle_state === 'completed');
    }

    protected function casts(): array
    {
        return [
            'recurrence_rule' => 'array',
            'is_multi_session' => 'boolean',
            'advance_notice_days' => 'integer',
            'day_preference_is_hard' => 'boolean',
            'revision' => 'integer',
            'deadline_on' => 'date:Y-m-d',
            'deadline_is_hard' => 'boolean',
        ];
    }
}

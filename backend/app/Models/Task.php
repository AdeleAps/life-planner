<?php

namespace App\Models;

use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'client_request_id',
    'title',
    'description',
    'source',
    'kind',
    'type',
    'priority',
    'energy_cost',
    'recurrence_type',
    'recurrence_rule',
    'is_multi_session',
    'deadline_on',
    'deadline_is_hard',
    'lifecycle_state',
    'revision',
])]
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'recurrence_rule' => 'array',
            'is_multi_session' => 'boolean',
            'deadline_on' => 'date:Y-m-d',
            'deadline_is_hard' => 'boolean',
        ];
    }
}

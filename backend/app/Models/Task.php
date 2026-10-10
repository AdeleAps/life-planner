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
    'duration_min_minutes',
    'duration_max_minutes',
    'lifecycle_state',
    'revision',
])]
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;
}

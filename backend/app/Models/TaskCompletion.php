<?php

namespace App\Models;

use Database\Factories\TaskCompletionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['task_id', 'client_request_id', 'completion_type', 'completed_at'])]
class TaskCompletion extends Model
{
    /** @use HasFactory<TaskCompletionFactory> */
    use HasFactory;

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ScheduledTaskResource;
use App\Models\ScheduledTask;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Validator;

class ScheduledTaskController extends Controller
{
    public function pendingReview(): AnonymousResourceCollection
    {
        $today = CarbonImmutable::now('Europe/Riga')->toDateString();

        $scheduledTasks = ScheduledTask::query()
            ->where('plan_date', '<=', $today)
            ->where('status', 'planned')
            ->whereHas('task', fn (Builder $query): Builder => $query->where('lifecycle_state', 'active'))
            ->with('task:id,title,type,recurrence_type,is_multi_session,lifecycle_state,revision')
            ->orderBy('plan_date')
            ->orderBy('id')
            ->get();

        return ScheduledTaskResource::collection($scheduledTasks);
    }

    public function index(string $date): AnonymousResourceCollection
    {
        Validator::make(['date' => $date], ['date' => ['required', 'date_format:Y-m-d']])->validate();

        $scheduledTasks = ScheduledTask::query()
            ->where('plan_date', $date)
            ->with('task:id,title,type,recurrence_type,is_multi_session,lifecycle_state,revision')
            ->orderBy('id')
            ->get();

        return ScheduledTaskResource::collection($scheduledTasks);
    }
}

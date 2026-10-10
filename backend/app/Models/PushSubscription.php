<?php

namespace App\Models;

use Database\Factories\PushSubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['endpoint_hash', 'endpoint', 'public_key', 'auth_token'])]
class PushSubscription extends Model
{
    /** @use HasFactory<PushSubscriptionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'endpoint' => 'encrypted',
            'auth_token' => 'encrypted',
            'last_review_plan_date' => 'date',
        ];
    }
}

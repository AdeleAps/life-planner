<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PushSubscriptionController extends Controller
{
    public function configuration(): JsonResponse
    {
        $available = $this->isConfigured();

        return response()->json([
            'available' => $available,
            'public_key' => $available ? config('services.web_push.public_key') : null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $this->isConfigured()) {
            return response()->json(['message' => 'Reminders are not configured yet.'], 503);
        }

        $subscription = $this->validatedSubscription($request);
        $endpoint = $subscription['endpoint'];

        PushSubscription::query()->updateOrCreate(
            ['endpoint_hash' => hash('sha256', $endpoint)],
            [
                'endpoint' => $endpoint,
                'public_key' => $subscription['keys']['p256dh'],
                'auth_token' => $subscription['keys']['auth'],
            ],
        );

        return response()->json(['registered' => true]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $subscription = $this->validatedSubscription($request);

        PushSubscription::query()
            ->where('endpoint_hash', hash('sha256', $subscription['endpoint']))
            ->delete();

        return response()->json(['registered' => false]);
    }

    /**
     * @return array{endpoint: string, keys: array{p256dh: string, auth: string}}
     */
    private function validatedSubscription(Request $request): array
    {
        $values = Validator::make($request->all(), [
            'endpoint' => ['required', 'string', 'url', 'max:2048'],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]+$/', 'max:255'],
            'keys.auth' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]+$/', 'max:255'],
        ])->validate();

        $host = strtolower((string) parse_url($values['endpoint'], PHP_URL_HOST));
        $scheme = strtolower((string) parse_url($values['endpoint'], PHP_URL_SCHEME));

        if ($scheme !== 'https' || ! str_ends_with($host, '.push.apple.com')) {
            throw ValidationException::withMessages(['endpoint' => 'Use an iPhone Planner subscription.']);
        }

        return $values;
    }

    private function isConfigured(): bool
    {
        return filled(config('services.web_push.public_key'))
            && filled(config('services.web_push.private_key'))
            && filled(config('services.web_push.subject'));
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PlanningDraftGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PlanningDraftController extends Controller
{
    public function __invoke(Request $request, string $date, PlanningDraftGenerator $generator): JsonResponse
    {
        Validator::make(['date' => $date], ['date' => ['required', 'date_format:Y-m-d']])->validate();
        $values = $request->validate(['client_request_id' => ['required', 'uuid']]);

        return response()->json(['data' => $generator->generate($date, $values['client_request_id'])]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AdvanceOfferPlanner;
use Illuminate\Http\JsonResponse;

class AdvanceOfferController extends Controller
{
    public function index(AdvanceOfferPlanner $planner): JsonResponse
    {
        return response()->json(['data' => $planner->pending()]);
    }

    public function refresh(AdvanceOfferPlanner $planner): JsonResponse
    {
        return response()->json(['data' => $planner->prepare()]);
    }

    public function accept(int $offer, AdvanceOfferPlanner $planner): JsonResponse
    {
        return response()->json($planner->accept($offer));
    }

    public function decline(int $offer, AdvanceOfferPlanner $planner): JsonResponse
    {
        return response()->json($planner->decline($offer));
    }
}

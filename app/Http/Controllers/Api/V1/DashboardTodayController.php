<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Reports\DashboardMetrics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardTodayController extends Controller
{
    public function __invoke(Request $request, DashboardMetrics $metrics): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $counts = $metrics->forUser($user);

        return response()->json([
            'data' => [
                'visits_today' => $counts->visitsToday,
                'pending_dcrs' => $counts->pendingDcrs,
                'pending_approvals' => $counts->pendingApprovals,
                'punched_in_today' => $counts->punchedInToday,
            ],
        ]);
    }
}

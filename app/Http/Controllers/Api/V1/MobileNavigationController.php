<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Mobile\MobileNavigationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileNavigationController extends Controller
{
    public function __invoke(Request $request, MobileNavigationService $navigation): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return response()->json($navigation->forUser($user));
    }
}

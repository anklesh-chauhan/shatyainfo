<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Field\FieldSgipStockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SampleLedgerController extends Controller
{
    public function __invoke(Request $request, FieldSgipStockService $stock): JsonResponse
    {
        return response()->json(['data' => $stock->snapshot($request->user())]);
    }
}

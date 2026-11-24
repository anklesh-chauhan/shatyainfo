<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StockLedgerRequest;
use App\Services\Inventory\StockLedger;
use Illuminate\Http\JsonResponse;

class StockLedgerController extends Controller
{
    public function __construct(private StockLedger $stocks) {}

    public function index(StockLedgerRequest $request): JsonResponse
    {
        return response()->json($this->stocks->list($request->user(), $request->validated())->toArray()
            + ['fetched_at' => now()->toIso8601String(), 'movement_types' => StockLedger::TYPES])->header('Cache-Control', 'private, no-store');
    }

    public function options(StockLedgerRequest $request, string $type): JsonResponse
    {
        return response()->json($this->stocks->options($request->user(), $type, trim((string) $request->validated('search'))))
            ->header('Cache-Control', 'private, no-store');
    }
}

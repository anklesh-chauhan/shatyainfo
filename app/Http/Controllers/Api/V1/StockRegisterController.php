<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StockRegisterRequest;
use App\Services\Inventory\StockRegister;
use Illuminate\Http\JsonResponse;

class StockRegisterController extends Controller
{
    public function __construct(private StockRegister $stocks) {}

    public function index(StockRegisterRequest $request): JsonResponse
    {
        return response()->json($this->stocks->list($request->user(), $request->validated())->toArray()
            + ['fetched_at' => now()->toIso8601String()])->header('Cache-Control', 'private, no-store');
    }

    public function options(StockRegisterRequest $request, string $type): JsonResponse
    {
        return response()->json($this->stocks->options($request->user(), $type, trim((string) $request->validated('search'))))
            ->header('Cache-Control', 'private, no-store');
    }
}

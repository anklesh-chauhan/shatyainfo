<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ItemMaster;
use Illuminate\Http\JsonResponse;

class OrderCatalogController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => ItemMaster::query()->orderBy('item_name')
            ->get(['id', 'item_code', 'item_name', 'selling_price'])]);
    }
}

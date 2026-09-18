<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ItemResource;
use App\Services\Field\FieldItemService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ItemController extends Controller
{
    public function __invoke(Request $request, FieldItemService $itemService): AnonymousResourceCollection
    {
        $type = $request->query('type');
        $search = $request->query('search');
        $perPage = (int) $request->query('per_page', 50);

        $items = $itemService->catalog(
            is_string($type) && $type !== '' ? $type : null,
            is_string($search) && $search !== '' ? $search : null,
            $perPage > 0 ? $perPage : 50,
        );

        return ItemResource::collection($items);
    }
}

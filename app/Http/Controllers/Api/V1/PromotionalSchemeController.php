<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PromotionalSchemeRegisterRequest;
use App\Http\Requests\Api\V1\SavePromotionalSchemeRequest;
use App\Models\PromotionalScheme;
use App\Services\Marketing\PromotionalSchemeRegister;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PromotionalSchemeController extends Controller
{
    public function __construct(private PromotionalSchemeRegister $schemes) {}

    public function index(PromotionalSchemeRegisterRequest $request): JsonResponse
    {
        $actor = $request->user();
        $page = $this->schemes->list($actor, $request->validated());

        return response()->json($page->toArray() + ['can_create' => $actor->can('create', PromotionalScheme::class)]);
    }

    public function options(PromotionalSchemeRegisterRequest $request, string $type): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor->can('viewAny', PromotionalScheme::class)
            && ($actor->can('create', PromotionalScheme::class) || $actor->can('Update:PromotionalScheme')), 403);
        $page = $this->schemes->options($actor, $type, (string) $request->validated('search'));

        return response()->json(['data' => $page->items(), 'meta' => ['last_page' => $page->lastPage()]]);
    }

    public function show(Request $request, int $scheme): JsonResponse
    {
        $actor = $request->user();

        return response()->json(['data' => $this->schemes->detail($actor, $this->schemes->find($actor, $scheme))]);
    }

    public function store(SavePromotionalSchemeRequest $request): JsonResponse
    {
        $actor = $request->user();
        $record = $this->schemes->save($actor, null, $request->validated());

        return response()->json(['data' => $this->schemes->detail($actor, $record)], 201);
    }

    public function update(SavePromotionalSchemeRequest $request, int $scheme): JsonResponse
    {
        $actor = $request->user();
        $record = $this->schemes->save($actor, $scheme, $request->validated());

        return response()->json(['data' => $this->schemes->detail($actor, $record)]);
    }

    public function records(PromotionalSchemeRegisterRequest $request, string $action): JsonResponse
    {
        $count = $this->schemes->changeRecords($request->user(), array_map(intval(...), $request->validated('ids')), $action);

        return response()->json(['data' => ['count' => $count]]);
    }
}

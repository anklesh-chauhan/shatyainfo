<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\MarketingCampaignRecordsRequest;
use App\Http\Requests\Api\V1\MarketingCampaignRegisterRequest;
use App\Http\Requests\Api\V1\SaveMarketingCampaignRequest;
use App\Models\MarketingCampaign;
use App\Services\Marketing\MarketingCampaignRegister;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarketingCampaignController extends Controller
{
    public function __construct(private MarketingCampaignRegister $campaigns) {}

    public function index(MarketingCampaignRegisterRequest $request): JsonResponse
    {
        $actor = $request->user();
        $page = $this->campaigns->list($actor, $request->validated());

        return response()->json($page->toArray() + ['can_create' => $actor->can('create', MarketingCampaign::class)]);
    }

    public function options(MarketingCampaignRegisterRequest $request, string $type): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor->can('viewAny', MarketingCampaign::class)
            && ($actor->can('create', MarketingCampaign::class) || $actor->can('Update:MarketingCampaign')), 403);
        $page = $this->campaigns->options($actor, $type, (string) $request->validated('search'));

        return response()->json(['data' => $page->items(), 'meta' => ['last_page' => $page->lastPage()]]);
    }

    public function show(Request $request, int $campaign): JsonResponse
    {
        $actor = $request->user();

        return response()->json(['data' => $this->campaigns->detail($actor, $this->campaigns->find($actor, $campaign))]);
    }

    public function store(SaveMarketingCampaignRequest $request): JsonResponse
    {
        $actor = $request->user();
        $campaign = $this->campaigns->save($actor, null, $request->validated());

        return response()->json(['data' => $this->campaigns->detail($actor, $campaign)], 201);
    }

    public function update(SaveMarketingCampaignRequest $request, int $campaign): JsonResponse
    {
        $actor = $request->user();
        $record = $this->campaigns->save($actor, $campaign, $request->validated());

        return response()->json(['data' => $this->campaigns->detail($actor, $record)]);
    }

    public function records(MarketingCampaignRecordsRequest $request, string $action): JsonResponse
    {
        $count = $this->campaigns->changeRecords($request->user(), array_map(intval(...), $request->validated('ids')), $action);

        return response()->json(['data' => ['count' => $count]]);
    }
}

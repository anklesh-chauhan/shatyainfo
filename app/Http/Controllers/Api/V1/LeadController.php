<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreLeadNoteRequest;
use App\Http\Requests\Api\V1\StoreLeadRequest;
use App\Http\Requests\Api\V1\UpdateLeadRequest;
use App\Http\Resources\Api\V1\DealResource;
use App\Http\Resources\Api\V1\LeadActivityResource;
use App\Http\Resources\Api\V1\LeadItemResource;
use App\Http\Resources\Api\V1\LeadNoteResource;
use App\Http\Resources\Api\V1\LeadResource;
use App\Models\Deal;
use App\Services\Field\FieldDealService;
use App\Services\Field\FieldLeadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LeadController extends Controller
{
    public function __construct(private FieldLeadService $leads) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $search = $request->query('search');
        $perPage = (int) $request->query('per_page', 50);

        return LeadResource::collection(
            $this->leads->list(
                $request->user(),
                is_string($search) && $search !== '' ? $search : null,
                $perPage,
            ),
        );
    }

    public function options(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->leads->options($request->user())]);
    }

    public function show(Request $request, int $lead): LeadResource
    {
        return new LeadResource($this->leads->findVisible($request->user(), $lead));
    }

    public function activities(Request $request, int $lead): AnonymousResourceCollection
    {
        return LeadActivityResource::collection(
            $this->leads->activities($request->user(), $lead, (int) $request->query('per_page', 50)),
        );
    }

    public function notes(Request $request, int $lead): AnonymousResourceCollection
    {
        return LeadNoteResource::collection(
            $this->leads->notes($request->user(), $lead, (int) $request->query('per_page', 50)),
        );
    }

    public function items(Request $request, int $lead): AnonymousResourceCollection
    {
        return LeadItemResource::collection($this->leads->items($request->user(), $lead));
    }

    public function storeNote(StoreLeadNoteRequest $request, int $lead): LeadNoteResource
    {
        $user = $request->user();

        return new LeadNoteResource(
            $this->leads->addNote($user, $this->leads->findVisible($user, $lead), $request->validated('note')),
        );
    }

    public function convert(Request $request, int $lead, FieldDealService $deals): JsonResponse
    {
        $user = $request->user();
        $record = $this->leads->findVisible($user, $lead);
        $deal = $this->leads->convert($user, $record);

        $visibleDeal = $user->can('viewAny', Deal::class)
            ? Deal::query()->applyVisibility('Deal')->whereKey($deal->id)->exists()
            : false;

        return response()->json([
            'data' => [
                'lead' => new LeadResource($this->leads->findVisible($user, $record->id)),
                'deal' => $visibleDeal
                    ? new DealResource($deals->findVisible($user, $deal->id))
                    : ['id' => $deal->id, 'reference_code' => $deal->reference_code, 'deal_name' => $deal->deal_name],
                'deal_visible' => $visibleDeal,
            ],
        ]);
    }

    public function store(StoreLeadRequest $request): LeadResource
    {
        return new LeadResource(
            $this->leads->store($request->user(), $request->validated()),
        );
    }

    public function update(UpdateLeadRequest $request, int $lead): LeadResource
    {
        $record = $this->leads->findVisible($request->user(), $lead);

        return new LeadResource(
            $this->leads->update($request->user(), $record, $request->validated()),
        );
    }
}

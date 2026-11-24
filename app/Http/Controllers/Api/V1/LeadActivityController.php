<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DeleteLeadActivitiesRequest;
use App\Http\Requests\Api\V1\ListLeadActivitiesRequest;
use App\Http\Requests\Api\V1\SaveLeadActivityRequest;
use App\Http\Resources\Api\V1\LeadActivityResource;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Services\Field\FieldLeadActivityService;
use App\Services\Field\FieldLeadService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LeadActivityController extends Controller
{
    public function __construct(private FieldLeadActivityService $activities, private FieldLeadService $leads) {}

    public function index(ListLeadActivitiesRequest $request): AnonymousResourceCollection
    {
        $actor = $request->user();
        $visibleLeadIds = $actor->can(['ViewAny:Lead', 'View:Lead'])
            ? Lead::query()->applyVisibility('Lead', $actor)->select('leads.id')
            : [];

        $activities = LeadActivity::query()
            ->applyVisibility('LeadActivity', $actor)
            ->with([
                'approval',
                'user:id,name',
                'lead' => fn (BelongsTo $lead): Builder => $lead->getQuery()
                    ->whereIn('leads.id', $visibleLeadIds)
                    ->with(['accountMaster:id,name', 'contactDetail:id,salutation,first_name,last_name']),
            ])
            ->when($request->validated('from'), fn (Builder $query, string $from): Builder => $query->whereDate('created_at', '>=', $from))
            ->when($request->validated('to'), fn (Builder $query, string $to): Builder => $query->whereDate('created_at', '<=', $to));

        $search = $request->validated('search');
        if (filled($search)) {
            $term = '%'.$search.'%';
            $activities->where(fn (Builder $query): Builder => $query
                ->whereLike('activity_type', $term)
                ->orWhereLike('description', $term)
                ->orWhereHas('user', fn (Builder $user): Builder => $user->whereLike('name', $term))
                ->orWhereHas('lead', fn (Builder $lead): Builder => $lead->whereIn('leads.id', $visibleLeadIds)
                    ->where(fn (Builder $parent): Builder => $parent
                        ->whereLike('reference_code', $term)
                        ->orWhereHas('accountMaster', fn (Builder $account): Builder => $account->whereLike('name', $term))
                        ->orWhereHas('contactDetail', fn (Builder $contact): Builder => $contact
                            ->whereLike('first_name', $term)->orWhereLike('last_name', $term)))));
        }

        return LeadActivityResource::collection(
            $activities->latest()->orderByDesc('id')->paginate($request->integer('per_page', 50)),
        )->additional(['can_create' => $this->activities->canCreate($actor)]);
    }

    public function forLead(Request $request, int $lead): AnonymousResourceCollection
    {
        $actor = $request->user();

        return LeadActivityResource::collection($this->leads->activities($actor, $lead, $request->integer('per_page', 50)))
            ->additional(['can_create' => $actor->can('View:LeadActivity') && $this->activities->canCreate($actor)]);
    }

    public function users(ListLeadActivitiesRequest $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless($this->activities->canCreate($actor) || (
            $actor->can('Update:LeadActivity') && $this->activities->canAccessLeads($actor)
        ), 403);

        $search = $request->validated('search');
        $users = $this->activities->selectableUsers($actor)
            ->when(filled($search), fn (Builder $query): Builder => $query->whereLike('name', '%'.$search.'%'))
            ->orderBy('name')->orderBy('id')
            ->paginate($request->integer('per_page', 50), ['id', 'name as label']);

        return response()->json(['data' => $users->items(), 'meta' => ['last_page' => $users->lastPage()]]);
    }

    public function store(SaveLeadActivityRequest $request): LeadActivityResource
    {
        return new LeadActivityResource($this->activities->create($request->user(), $this->leadId($request), $request->validated()));
    }

    public function update(SaveLeadActivityRequest $request): LeadActivityResource
    {
        return new LeadActivityResource($this->activities->update(
            $request->user(), $this->leadId($request), (int) $request->route('activity'), $request->validated(),
        ));
    }

    public function destroy(Request $request): JsonResponse
    {
        $deleted = $this->activities->delete($request->user(), $this->leadId($request), [(int) $request->route('activity')]);

        return response()->json(['data' => ['deleted' => $deleted]]);
    }

    public function bulkDelete(DeleteLeadActivitiesRequest $request): JsonResponse
    {
        $deleted = $this->activities->delete($request->user(), $this->leadId($request), array_map(intval(...), $request->validated('ids')));

        return response()->json(['data' => ['deleted' => $deleted]]);
    }

    private function leadId(Request $request): ?int
    {
        $lead = $request->route('lead');

        return $lead === null ? null : (int) $lead;
    }
}

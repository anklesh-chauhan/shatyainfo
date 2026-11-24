<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CreateFieldTeamHqRequest;
use App\Http\Requests\Api\V1\FieldTeamAssignmentOptionsRequest;
use App\Http\Requests\Api\V1\SaveFieldTeamAssignmentRequest;
use App\Models\AccountMaster;
use App\Models\FieldUserHq;
use App\Models\User;
use App\Services\CompanyLaunchService;
use App\Services\Field\FieldTeamOnboardingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;

class FieldTeamAssignmentController extends Controller
{
    public function __construct(private FieldTeamOnboardingService $assignments) {}

    public function representatives(FieldTeamAssignmentOptionsRequest $request): JsonResponse
    {
        return $this->options($request, User::query());
    }

    public function representative(FieldTeamAssignmentOptionsRequest $request, int $representative, CompanyLaunchService $launch): JsonResponse
    {
        $user = User::query()->findOrFail($representative);
        $hq = FieldUserHq::query()->with('location')->where('user_id', $user->id)->first()?->location;

        return response()->json(['data' => [
            'representative' => $this->option($user),
            'current_hq' => $hq ? $this->option($hq) : null,
            'current_hq_eligible' => $hq && $this->assignments->availableHqs($user->id)->whereKey($hq->id)->exists(),
            'requires_hq' => $launch->requiresHq(),
            'requires_territory_linked_hq' => $launch->requiresTerritoryLinkedHq(),
            'can_create_hq' => $this->assignments->canCreateHq($request->user()),
            'can_assign_customers' => $request->user()->can('update', new AccountMaster),
        ]]);
    }

    public function hqs(FieldTeamAssignmentOptionsRequest $request, int $representative): JsonResponse
    {
        User::query()->findOrFail($representative);

        return $this->options($request, $this->assignments->availableHqs($representative));
    }

    public function customers(FieldTeamAssignmentOptionsRequest $request): JsonResponse
    {
        abort_unless($request->user()->can('update', new AccountMaster), 403);

        return $this->options($request, AccountMaster::query()->applyVisibility('AccountMaster'));
    }

    public function store(SaveFieldTeamAssignmentRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->assignments->save($request->user(), $request->validated())]);
    }

    public function createHq(CreateFieldTeamHqRequest $request): JsonResponse
    {
        $hq = $this->assignments->createHq($request->user(), $request->validated('name'));

        return response()->json(['data' => $this->option($hq)], 201);
    }

    private function options(FieldTeamAssignmentOptionsRequest $request, Builder $query): JsonResponse
    {
        $search = trim((string) $request->validated('search'));
        $page = $query->when($search !== '', fn (Builder $query): Builder => $query->whereLike('name', '%'.$search.'%'))
            ->orderBy('name')->orderBy('id')->paginate(30, ['id', 'name']);

        return response()->json([
            'data' => $page->getCollection()->map($this->option(...)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    /** @return array{id: int, label: string} */
    private function option(Model $record): array
    {
        return ['id' => $record->id, 'label' => $record->name];
    }
}

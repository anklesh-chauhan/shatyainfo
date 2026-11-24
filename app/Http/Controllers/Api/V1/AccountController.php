<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AccountRegisterActionRequest;
use App\Http\Requests\Api\V1\AccountRegisterRequest;
use App\Http\Requests\Api\V1\StoreAccountRequest;
use App\Http\Requests\Api\V1\UpdateAccountRequest;
use App\Http\Resources\Api\V1\AccountResource;
use App\Models\AccountMaster;
use App\Models\AccountReportingMaster;
use App\Models\AccountStatus;
use App\Models\OrganizationalUnit;
use App\Models\RatingType;
use App\Models\TypeMaster;
use App\Services\Field\FieldAccountService;
use App\Services\Field\FieldTeamOnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AccountController extends Controller
{
    public function reportingOptions(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('viewAny', AccountMaster::class), 403);

        return response()->json(['data' => [
            'masters' => AccountReportingMaster::where('is_active', true)->orderBy('name')->get(['id', 'kind', 'code', 'name', 'is_monetary']),
            'ratings' => RatingType::where('is_lifecycle', false)->orderBy('name')->get(['id', 'name']),
            'statuses' => AccountStatus::orderBy('name')->get(['id', 'code', 'name']),
            'divisions' => OrganizationalUnit::query()->activeDivisions()->get(['id', 'code', 'name']),
            'activation_requirements' => TypeMaster::where('typeable_type', AccountMaster::class)->get(['id', 'activation_requirements']),
        ]]);
    }

    public function __construct(
        private FieldAccountService $accountService,
    ) {}

    public function index(AccountRegisterRequest $request): AnonymousResourceCollection
    {
        $typeMasterId = $request->query('type_master_id');
        $territoryId = $request->query('territory_id');
        $patchId = $request->query('patch_id');
        $search = $request->query('search');
        $perPage = (int) $request->query('per_page', 50);

        return AccountResource::collection(
            $this->accountService->list(
                $request->user(),
                is_string($search) && $search !== '' ? $search : null,
                is_numeric($typeMasterId) ? (int) $typeMasterId : null,
                is_numeric($territoryId) ? (int) $territoryId : null,
                is_numeric($patchId) ? (int) $patchId : null,
                $perPage > 0 ? $perPage : 50,
                $request->boolean('register') ? ['trash' => 'active', ...$request->validated()] : [],
            ),
        )->additional([
            'can_create' => $request->user()->can('create', AccountMaster::class),
            ...($request->boolean('register') ? ['register' => [
                'can_assign_owner' => $request->user()->can('create', AccountMaster::class) && FieldTeamOnboardingService::canManage($request->user()),
                'can_bulk_restore' => $request->user()->can('restoreAny', AccountMaster::class),
                'can_bulk_force_delete' => $request->user()->can('forceDeleteAny', AccountMaster::class),
            ]] : []),
        ]);
    }

    public function registerOptions(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->accountService->registerOptions($request->user())]);
    }

    public function ownerOptions(Request $request): JsonResponse
    {
        $search = $request->query('search');
        $owners = $this->accountService->ownerOptions($request->user(), is_string($search) ? mb_substr($search, 0, 100) : '');

        return response()->json(['data' => $owners->through(fn ($owner): array => ['id' => $owner->id, 'label' => $owner->name])->items(),
            'meta' => ['last_page' => $owners->lastPage()]]);
    }

    public function registerAction(AccountRegisterActionRequest $request): JsonResponse
    {
        return response()->json(['data' => ['updated' => $this->accountService->applyLifecycle(
            $request->user(), $request->validated('action'), array_map('intval', $request->validated('ids')),
        )]]);
    }

    public function relatedAction(AccountRegisterActionRequest $request, int $account): JsonResponse
    {
        $actor = $request->user();
        $record = $this->accountService->findVisible($actor, $account);
        $updated = $this->accountService->applyRelatedAction($actor, $record, $request->validated('group'),
            $request->validated('action'), array_map('intval', $request->validated('ids')));

        return response()->json(['data' => ['updated' => $updated]]);
    }

    public function show(AccountRegisterRequest $request, int $account): AccountResource
    {
        return new AccountResource(
            $this->accountService->findVisible($request->user(), $account, withTrash: $request->boolean('register')),
        );
    }

    public function store(StoreAccountRequest $request): AccountResource
    {
        return new AccountResource(
            $this->accountService->store($request->user(), $request->validated()),
        );
    }

    public function update(UpdateAccountRequest $request, int $account): AccountResource
    {
        $record = $this->accountService->findVisible($request->user(), $account);

        return new AccountResource(
            $this->accountService->update($request->user(), $record, $request->validated()),
        );
    }
}

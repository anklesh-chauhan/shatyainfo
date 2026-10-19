<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
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
            'divisions' => OrganizationalUnit::where('is_active', true)->whereHas('typeMaster', fn ($q) => $q->where('name', 'Division')->where('typeable_type', OrganizationalUnit::class))->get(['id', 'code', 'name']),
            'activation_requirements' => TypeMaster::where('typeable_type', AccountMaster::class)->get(['id', 'activation_requirements']),
        ]]);
    }

    public function __construct(
        private FieldAccountService $accountService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
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
            ),
        );
    }

    public function show(Request $request, int $account): AccountResource
    {
        return new AccountResource(
            $this->accountService->findVisible($request->user(), $account),
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

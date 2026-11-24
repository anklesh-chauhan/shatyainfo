<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PatchActionRequest;
use App\Http\Requests\Api\V1\PatchRegisterRequest;
use App\Http\Requests\Api\V1\PatchWorkbookRequest;
use App\Http\Requests\Api\V1\SavePatchRequest;
use App\Http\Resources\Api\V1\TerritoryResource;
use App\Models\AccountMaster;
use App\Models\Patch;
use App\Models\User;
use App\Services\DataImport\FieldTravelTemplate;
use App\Services\Field\FieldPatchService;
use App\Services\Field\FieldPatchWorkbook;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PatchController extends Controller
{
    public function __construct(private FieldPatchService $patches, private FieldPatchWorkbook $workbooks) {}

    public function index(PatchRegisterRequest $request): JsonResponse
    {
        $actor = $request->user();
        $page = $this->patches->list($actor, $request->validated());
        $allowed = $this->allowed($actor, $page->getCollection());

        return $this->page($page, fn (Patch $patch): array => $this->payload($actor, $patch, $allowed), [
            'register' => ['can_create' => $actor->can('create', Patch::class), 'can_import' => $this->workbooks->canAccess($actor)],
        ]);
    }

    public function show(Request $request, int $patch): JsonResponse
    {
        return $this->single($request->user(), $this->patches->find($request->user(), $patch, trashed: true));
    }

    public function store(SavePatchRequest $request): JsonResponse
    {
        return $this->single($request->user(), $this->patches->save($request->user(), $request->validated()), 201);
    }

    public function update(SavePatchRequest $request, int $patch): JsonResponse
    {
        return $this->single($request->user(), $this->patches->save($request->user(), $request->validated(), $patch));
    }

    public function trash(PatchActionRequest $request): JsonResponse
    {
        abort_unless($request->validated('action') === 'trash', 422);
        $count = $this->patches->trash($request->user(), array_map('intval', $request->validated('ids')));

        return response()->json(['data' => ['updated' => $count]]);
    }

    public function territories(PatchRegisterRequest $request): JsonResponse
    {
        return $this->page($this->patches->territoryOptions($request->user(), $request->validated()), TerritoryResource::option(...));
    }

    public function pinCodes(PatchRegisterRequest $request, int $territory): JsonResponse
    {
        return $this->page($this->patches->pinOptions($request->user(), $territory, $request->validated()), TerritoryResource::option(...));
    }

    public function customers(PatchRegisterRequest $request, int $patch): JsonResponse
    {
        return $this->page($this->patches->customers($request->user(), $patch, $request->validated(), false), fn (AccountMaster $account): array => [
            'id' => $account->id,
            'name' => $account->name,
            'code' => $account->account_code,
            'type' => $account->typeMaster?->parent?->name ?? $account->typeMaster?->name,
            'type_master_id' => $account->type_master_id,
            'sequence_no' => $account->pivot->sequence_no,
            'distance_km' => $account->pivot->distance_km,
            'primary_area' => $account->addresses->firstWhere('is_primary', true)?->areaTown?->area_town,
            'pin_codes' => $account->addresses->pluck('pin_code')->filter()->unique()->values(),
        ]);
    }

    public function customerTypes(PatchRegisterRequest $request, int $patch): JsonResponse
    {
        return $this->page($this->patches->customerTypes($request->user(), $patch, $request->validated()), TerritoryResource::option(...));
    }

    public function eligibleCustomers(PatchRegisterRequest $request, int $patch): JsonResponse
    {
        return $this->page($this->patches->customers($request->user(), $patch, $request->validated(), true),
            fn (AccountMaster $account): array => ['id' => $account->id, 'label' => implode(' · ', array_filter([$account->name, $account->account_code]))]);
    }

    public function changeCustomers(PatchActionRequest $request, int $patch): JsonResponse
    {
        abort_if($request->validated('action') === 'trash', 422);
        $count = $this->patches->changeCustomers($request->user(), $patch, $request->validated());

        return response()->json(['data' => ['updated' => $count]]);
    }

    public function workbookTemplate(Request $request): StreamedResponse
    {
        abort_unless($this->workbooks->canAccess($request->user()), 403);

        return response()->streamDownload(function (): void {
            $path = tempnam(sys_get_temp_dir(), 'patch-workbook-');
            try {
                app(FieldTravelTemplate::class)->write($path, patchesOnly: true);
                readfile($path);
            } finally {
                unlink($path);
            }
        }, 'patches.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function previewWorkbook(PatchWorkbookRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->workbooks->preview($request->user(), $request->file('file'))]);
    }

    public function latestWorkbook(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->workbooks->latest($request->user())]);
    }

    public function workbookStatus(PatchRegisterRequest $request, string $token): JsonResponse
    {
        return response()->json(['data' => $this->workbooks->status($request->user(), $token, $request->integer('page', 1))]);
    }

    public function importWorkbook(Request $request, string $token): JsonResponse
    {
        return response()->json(['data' => $this->workbooks->dispatch($request->user(), $token)]);
    }

    private function page(LengthAwarePaginator $page, callable $map, array $extra = []): JsonResponse
    {
        return response()->json([
            'data' => $page->through($map)->items(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage()],
            ...$extra,
        ]);
    }

    private function allowed(User $actor, Collection $patches): Collection
    {
        return $this->patches->assignableTerritories($actor)->whereKey($patches->pluck('territory_id')->unique()->all())->pluck('id');
    }

    private function single(User $actor, Patch $patch, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $this->payload($actor, $patch, $this->allowed($actor, collect([$patch])))], $status);
    }

    private function payload(User $actor, Patch $patch, Collection $allowed): array
    {
        return [
            'id' => $patch->id,
            'name' => $patch->name,
            'code' => $patch->code,
            'territory' => $patch->territory && $allowed->contains($patch->territory_id) ? TerritoryResource::option($patch->territory) : null,
            'city_pin_code' => $patch->cityPinCode ? TerritoryResource::option($patch->cityPinCode) : null,
            'description' => $patch->description,
            'color' => $patch->color,
            'trashed' => $patch->trashed(),
            'approval_status' => $patch->approval?->approval_status ?? $patch->legacyApprovalState(),
            ...$this->patches->capabilities($actor, $patch, $allowed),
        ];
    }
}

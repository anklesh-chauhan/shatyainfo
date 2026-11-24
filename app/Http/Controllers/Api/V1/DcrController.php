<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DcrForDateRequest;
use App\Http\Requests\Api\V1\DcrRegisterLifecycleRequest;
use App\Http\Requests\Api\V1\ListDcrRegisterRequest;
use App\Http\Resources\Api\V1\SalesDcrResource;
use App\Services\Field\DcrRegisterService;
use App\Services\Field\FieldDcrService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DcrController extends Controller
{
    public function __construct(
        private FieldDcrService $dcrService,
    ) {}

    public function index(ListDcrRegisterRequest $request, DcrRegisterService $register): JsonResponse
    {
        return response()->json($register->index($request->user(), $request->validated()));
    }

    public function registerDetail(ListDcrRegisterRequest $request, int $dcr, DcrRegisterService $register): JsonResponse
    {
        return response()->json(['data' => $register->detail($request->user(), $dcr, $request->validated('scope') === 'team')]);
    }

    public function registerAction(DcrRegisterLifecycleRequest $request, DcrRegisterService $register): JsonResponse
    {
        $updated = $register->apply($request->user(), array_map('intval', $request->validated('ids')),
            $request->validated('scope') === 'team', $request->validated('action'));

        return response()->json(['data' => ['updated' => $updated]]);
    }

    public function today(DcrForDateRequest $request): SalesDcrResource
    {
        return new SalesDcrResource(
            $this->dcrService->forDate($request->user(), $request->onDate(), authorizeCreation: true),
        );
    }

    public function show(Request $request, int $dcr): SalesDcrResource
    {
        return new SalesDcrResource(
            $this->dcrService->findOwned($request->user(), $dcr),
        );
    }

    public function submit(Request $request, int $dcr): SalesDcrResource
    {
        $record = $this->dcrService->findOwned($request->user(), $dcr);

        return new SalesDcrResource(
            $this->dcrService->submit($request->user(), $record),
        );
    }
}

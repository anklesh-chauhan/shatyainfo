<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DcrForDateRequest;
use App\Http\Resources\Api\V1\SalesDcrResource;
use App\Services\Field\FieldDcrService;
use Illuminate\Http\Request;

class DcrController extends Controller
{
    public function __construct(
        private FieldDcrService $dcrService,
    ) {}

    public function today(DcrForDateRequest $request): SalesDcrResource
    {
        return new SalesDcrResource(
            $this->dcrService->forDate($request->user(), $request->onDate()),
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

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DeleteCompaniesRequest;
use App\Http\Requests\Api\V1\SaveCompanyAddressRequest;
use App\Http\Requests\Api\V1\SaveCompanyRequest;
use App\Http\Resources\Api\V1\AddressResource;
use App\Http\Resources\Api\V1\CompanyResource;
use App\Models\Company;
use App\Services\Field\FieldCompanyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CompanyController extends Controller
{
    public function __construct(private FieldCompanyService $companies) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $actor = $request->user();

        return CompanyResource::collection($this->companies->list($actor, $this->search($request), max(1, min($request->integer('per_page', 25), 100))))
            ->additional(['register' => ['can_create' => $actor->can('create', Company::class), 'can_choose_industry' => $this->companies->canChooseIndustry($actor)]]);
    }

    public function show(Request $request, int $company): CompanyResource
    {
        return new CompanyResource($this->companies->show($request->user(), $company));
    }

    public function store(SaveCompanyRequest $request): CompanyResource
    {
        return new CompanyResource($this->companies->save($request->user(), $request->validated()));
    }

    public function update(SaveCompanyRequest $request, int $company): CompanyResource
    {
        return new CompanyResource($this->companies->save($request->user(), $request->validated(), $company));
    }

    public function storeForContact(SaveCompanyRequest $request): JsonResponse
    {
        $company = $this->companies->createForContact($request->user(), $request->validated());

        return response()->json(['data' => ['id' => $company->id, 'label' => $company->name]], 201);
    }

    public function delete(DeleteCompaniesRequest $request): JsonResponse
    {
        return response()->json(['data' => ['updated' => $this->companies->delete($request->user(), array_map('intval', $request->validated('ids')))]]);
    }

    public function industryOptions(Request $request): JsonResponse
    {
        $page = $this->companies->industryOptions($request->user(), $this->search($request));

        return response()->json(['data' => $page->through(fn ($industry): array => ['id' => $industry->id, 'label' => $industry->name])->items(),
            'meta' => ['last_page' => $page->lastPage()]]);
    }

    public function saveAddress(SaveCompanyAddressRequest $request, int $company, ?int $address = null): AddressResource
    {
        return new AddressResource($this->companies->changeAddress($request->user(), $company, $address, $request->validated()));
    }

    public function deleteAddress(Request $request, int $company, int $address): JsonResponse
    {
        $this->companies->changeAddress($request->user(), $company, $address, null);

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function reorderAddresses(DeleteCompaniesRequest $request, int $company): JsonResponse
    {
        $this->companies->reorderAddresses($request->user(), $company, array_map('intval', $request->validated('ids')));

        return response()->json(['data' => ['reordered' => true]]);
    }

    private function search(Request $request): string
    {
        $search = $request->query('search');

        return is_string($search) ? mb_substr(trim($search), 0, 100) : '';
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreAccountAddressRequest;
use App\Http\Requests\Api\V1\UpdateAccountAddressRequest;
use App\Http\Resources\Api\V1\AddressResource;
use App\Services\Field\FieldAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AccountAddressController extends Controller
{
    public function __construct(
        private FieldAccountService $accountService,
    ) {}

    public function index(Request $request, int $account): AnonymousResourceCollection
    {
        $record = $this->accountService->findVisible($request->user(), $account);

        return AddressResource::collection(
            $this->accountService->listAddresses($request->user(), $record),
        );
    }

    public function store(StoreAccountAddressRequest $request, int $account): AddressResource
    {
        $record = $this->accountService->findVisible($request->user(), $account);

        return new AddressResource(
            $this->accountService->storeAddress($request->user(), $record, $request->validated()),
        );
    }

    public function show(Request $request, int $account, int $address): AddressResource
    {
        $record = $this->accountService->findVisible($request->user(), $account);

        return new AddressResource(
            $this->accountService->findAddress($request->user(), $record, $address),
        );
    }

    public function update(UpdateAccountAddressRequest $request, int $account, int $address): AddressResource
    {
        $record = $this->accountService->findVisible($request->user(), $account);
        $addressRecord = $this->accountService->findAddress($request->user(), $record, $address);

        return new AddressResource(
            $this->accountService->updateAddress($request->user(), $record, $addressRecord, $request->validated()),
        );
    }

    public function destroy(Request $request, int $account, int $address): JsonResponse
    {
        $record = $this->accountService->findVisible($request->user(), $account);
        $addressRecord = $this->accountService->findAddress($request->user(), $record, $address);

        $this->accountService->deleteAddress($request->user(), $record, $addressRecord);

        return response()->json(['message' => 'Address deleted.']);
    }
}

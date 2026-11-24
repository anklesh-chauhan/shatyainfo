<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ContactRegisterRequest;
use App\Http\Requests\Api\V1\SaveContactAddressRequest;
use App\Http\Requests\Api\V1\SaveContactRequest;
use App\Http\Resources\Api\V1\AddressResource;
use App\Http\Resources\Api\V1\ContactResource;
use App\Models\ContactDetail;
use App\Services\Field\FieldCompanyService;
use App\Services\Field\FieldContactService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ContactController extends Controller
{
    public function __construct(private FieldContactService $contacts) {}

    public function index(ContactRegisterRequest $request, FieldCompanyService $companies): AnonymousResourceCollection
    {
        $search = $request->query('search');
        $perPage = (int) $request->query('per_page', 50);

        $actor = $request->user();
        $register = $request->boolean('register');

        return ContactResource::collection(
            $this->contacts->list(
                $actor,
                is_string($search) && $search !== '' ? $search : null,
                $perPage,
                $register,
                $request->filled('company_id') ? $request->integer('company_id') : null,
            ),
        )->additional($register ? ['register' => [
            'can_create' => $actor->can('create', ContactDetail::class),
            'options' => array_values(array_filter(['companies', 'designations', 'departments'], fn (string $kind): bool => $this->contacts->optionAccess($actor, $kind))),
            'create_options' => [
                ...array_values(array_filter(['designations', 'departments'], fn (string $kind): bool => $this->contacts->canCreateOption($actor, $kind))),
                ...($companies->canCreateForContact($actor) ? ['companies'] : []),
            ],
            'can_choose_company_industry' => $companies->canChooseIndustry($actor),
        ]] : []);
    }

    public function show(Request $request, int $contact): ContactResource
    {
        $actor = $request->user();

        return new ContactResource($this->contacts->detail($actor, $this->contacts->find($actor, $contact)));
    }

    public function store(SaveContactRequest $request): ContactResource
    {
        return new ContactResource($this->contacts->save($request->user(), $request->validated()));
    }

    public function update(SaveContactRequest $request, int $contact): ContactResource
    {
        return new ContactResource($this->contacts->save($request->user(), $request->validated(), $contact));
    }

    public function delete(ContactRegisterRequest $request): JsonResponse
    {
        return response()->json(['data' => ['updated' => $this->contacts->delete($request->user(), array_map('intval', $request->validated('ids')))]]);
    }

    public function options(Request $request, string $kind): JsonResponse
    {
        $search = $request->query('search');
        $options = $this->contacts->options($request->user(), $kind, is_string($search) ? mb_substr($search, 0, 100) : '');

        return response()->json(['data' => $options->through(fn ($record): array => ['id' => $record->id, 'label' => $record->name])->items(),
            'meta' => ['last_page' => $options->lastPage()]]);
    }

    public function createOption(ContactRegisterRequest $request, string $kind): JsonResponse
    {
        return response()->json(['data' => $this->contacts->createOption($request->user(), $kind, $request->validated('name'))], 201);
    }

    public function addresses(Request $request, int $contact): AnonymousResourceCollection
    {
        return AddressResource::collection($this->contacts->addresses($request->user(), $contact));
    }

    public function saveAddress(SaveContactAddressRequest $request, int $contact, ?int $address = null): AddressResource
    {
        return new AddressResource($this->contacts->changeAddress($request->user(), $contact, $address, $request->validated()));
    }

    public function deleteAddress(Request $request, int $contact, int $address): JsonResponse
    {
        $this->contacts->changeAddress($request->user(), $contact, $address, null);

        return response()->json(['data' => ['deleted' => true]]);
    }
}

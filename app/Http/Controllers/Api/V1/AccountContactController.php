<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SaveAccountContactRequest;
use App\Http\Resources\Api\V1\AccountResource;
use App\Services\Field\FieldAccountService;
use Illuminate\Http\Request;

class AccountContactController extends Controller
{
    public function __construct(private FieldAccountService $accounts) {}

    public function store(SaveAccountContactRequest $request, int $account): AccountResource
    {
        return $this->change($request, $account, 'create', data: $request->validated());
    }

    public function update(SaveAccountContactRequest $request, int $account, int $contact): AccountResource
    {
        return $this->change($request, $account, 'update', $contact, $request->validated());
    }

    public function attach(Request $request, int $account, int $contact): AccountResource
    {
        return $this->change($request, $account, 'attach', $contact);
    }

    public function detach(Request $request, int $account, int $contact): AccountResource
    {
        return $this->change($request, $account, 'detach', $contact);
    }

    private function change(Request $request, int $account, string $action, ?int $contact = null, array $data = []): AccountResource
    {
        $actor = $request->user();
        $record = $this->accounts->findVisible($actor, $account);

        return new AccountResource($this->accounts->changeContact($actor, $record, $action, $contact, $data));
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AccountTypeResource;
use App\Services\Field\FieldAccountService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AccountTypeController extends Controller
{
    public function __invoke(FieldAccountService $accountService): AnonymousResourceCollection
    {
        return AccountTypeResource::collection($accountService->types());
    }
}

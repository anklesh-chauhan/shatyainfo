<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ExpenseTypeResource;
use App\Services\Field\FieldExpenseService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ExpenseTypeController extends Controller
{
    public function __invoke(FieldExpenseService $expenseService): AnonymousResourceCollection
    {
        return ExpenseTypeResource::collection($expenseService->activeTypes());
    }
}

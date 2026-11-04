<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreFieldExpenseEventRequest;
use App\Http\Resources\Api\V1\SalesDcrExpenseResource;
use App\Services\Field\FieldDcrService;
use App\Services\Field\FieldExpenseService;
use App\Services\Field\FieldRecordedAt;
use Illuminate\Support\Facades\DB;

class FieldExpenseEventController extends Controller
{
    public function __invoke(StoreFieldExpenseEventRequest $request, FieldDcrService $dcrs, FieldExpenseService $expenses): SalesDcrExpenseResource
    {
        return DB::transaction(function () use ($request, $dcrs, $expenses): SalesDcrExpenseResource {
            $data = $request->validated();
            $time = FieldRecordedAt::resolve(['recorded_at' => $data['captured_at']]);
            abort_if($data['date'] > $time->toDateString(), 422, 'An expense date cannot be later than its capture day.');
            $user = $request->user();
            $user->newQuery()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $dcr = $dcrs->forDate($user, $data['date']);
            $dcr = $dcr->newQuery()->lockForUpdate()->findOrFail($dcr->id);
            $data['meta'] = [...($data['meta'] ?? []), 'captured_at' => $data['captured_at'], 'source' => 'offline_expense', 'operation_id' => $data['event_id']];

            return new SalesDcrExpenseResource($expenses->storeManual($user, $dcr, $data));
        });
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreFieldDcrEventRequest;
use App\Http\Resources\Api\V1\SalesDcrResource;
use App\Models\FieldDcrEntry;
use App\Services\Field\FieldDcrService;
use App\Services\Field\FieldRecordedAt;
use Illuminate\Support\Facades\DB;

class FieldDcrEventController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(StoreFieldDcrEventRequest $request, FieldDcrService $service): SalesDcrResource
    {
        return DB::transaction(function () use ($request, $service): SalesDcrResource {
            $data = $request->validated();
            $time = FieldRecordedAt::resolve(['recorded_at' => $data['captured_at']]);
            abort_unless($time->toDateString() === $data['date'], 422, 'Capture date must match the DCR date.');
            $user = $request->user();
            $user->newQuery()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_if(FieldDcrEntry::query()->where('event_id', $data['event_id'])->exists(), 409, 'This DCR event identity was already used.');
            $dcr = $service->forDate($user, $data['date']);
            $dcr = $dcr->newQuery()->lockForUpdate()->findOrFail($dcr->id);
            $service->assertEditable($dcr);
            FieldDcrEntry::query()->create(['event_id' => $data['event_id'], 'user_id' => $user->id,
                'sales_dcr_id' => $dcr->id, 'category' => $data['category'], 'remarks' => $data['remarks'],
                'captured_at' => $time, 'received_at' => now()]);

            return new SalesDcrResource($service->findOwned($user, $dcr->id));
        });
    }
}

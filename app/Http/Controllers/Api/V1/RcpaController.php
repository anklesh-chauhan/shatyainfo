<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreRcpaObservationRequest;
use App\Models\AccountMaster;
use App\Models\RcpaObservation;
use App\Services\Field\FieldRcpaService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RcpaController extends Controller
{
    public function options(Request $request, FieldRcpaService $service): JsonResponse
    {
        return response()->json(['data' => $service->options($request->user())]);
    }

    public function store(StoreRcpaObservationRequest $request, FieldRcpaService $service): JsonResponse
    {
        return response()->json(['data' => $service->capture($request->user(), $request->validated())], 201);
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->visible($request)->latest('observed_on')->latest('id')->paginate(50));
    }

    public function export(Request $request): StreamedResponse
    {
        $query = $this->visible($request);

        return response()->streamDownload(function () use ($query): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, ['Observation_ID', 'Date', 'Chemist_ID', 'SKU_ID', 'Stock_Status', 'Doctor_RX_Share', 'Offtake_Base_Units', 'Offtake_Days', 'Data_Flag'], ',', '"', '');
            foreach ($query->orderBy('id')->lazy(200) as $row) {
                fputcsv($stream, [$row->id, $row->observed_on->toDateString(), $row->chemist_id, $row->item_master_id,
                    $row->stock_status, $row->doctor_rx_share, $row->offtake_quantity, $row->offtake_days, '[ESTIMATED_FIELD]'], ',', '"', '');
            }
            fclose($stream);
        }, 'rcpa-estimates.csv');
    }

    private function visible(Request $request): Builder
    {
        abort_unless($request->user()->can('Capture:Rcpa') || $request->user()->can('View:Rcpa'), 403);
        $query = RcpaObservation::query();
        if ($request->user()->can('View:Rcpa')) {
            $query->whereIn('chemist_id', AccountMaster::query()->applyVisibility('AccountMaster')->select('id'));
        } else {
            $query->where('user_id', $request->user()->id);
        }

        return $query;
    }
}

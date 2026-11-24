<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RcpaRegisterRequest;
use App\Http\Requests\Api\V1\StoreRcpaObservationRequest;
use App\Models\RcpaObservation;
use App\Services\Field\FieldRcpaService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RcpaController extends Controller
{
    public function __construct(private FieldRcpaService $rcpa) {}

    public function options(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->rcpa->options($request->user())]);
    }

    public function store(StoreRcpaObservationRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->rcpa->capture($request->user(), $request->validated())], 201);
    }

    public function index(RcpaRegisterRequest $request): JsonResponse
    {
        $register = $request->boolean('register');
        $query = $this->visible($request, $register)->latest('observed_on')->latest('id');
        if (! $register) {
            return response()->json($query->paginate(50));
        }
        $page = $this->filtered($query, $request->validated())->with(['chemist:id,name', 'itemMaster:id,item_name'])
            ->paginate(50)->through(fn (RcpaObservation $record): array => $this->summary($record));
        $fieldData = $this->rcpa->canUseFieldData($request->user());

        return response()->json($page->toArray() + ['can_export' => $fieldData, 'can_due' => $fieldData]);
    }

    public function show(Request $request, int $observation): JsonResponse
    {
        $record = $this->visible($request, true)->with(['chemist:id,name', 'itemMaster:id,item_name', 'observer:id,name', 'therapyArea:id,name'])->findOrFail($observation);

        return response()->json(['data' => [
            ...$this->summary($record),
            'competitors' => $record->competitors,
            'remarks' => $record->remarks,
            'observer' => $record->observer?->name,
            'segment' => $record->therapyArea?->name,
        ]]);
    }

    public function due(RcpaRegisterRequest $request): JsonResponse
    {
        return response()->json($this->rcpa->dueChemists($request->user(), trim((string) $request->validated('search')))
            ->paginate(50)->through($this->rcpa->chemistDueStatus(...)));
    }

    public function export(RcpaRegisterRequest $request): StreamedResponse
    {
        $query = $this->filtered($this->visible($request), $request->validated());

        return response()->streamDownload(function () use ($query): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, ['Observation_ID', 'Date', 'Chemist_ID', 'SKU_ID', 'Stock_Status', 'Doctor_RX_Share', 'Offtake_Base_Units', 'Offtake_Days', 'Data_Flag'], ',', '"', '');
            foreach ($query->lazyById(200) as $row) {
                fputcsv($stream, [$row->id, $row->observed_on->toDateString(), $row->chemist_id, $row->item_master_id,
                    $row->stock_status, $row->doctor_rx_share, $row->offtake_quantity, $row->offtake_days, '[ESTIMATED_FIELD]'], ',', '"', '');
            }
            fclose($stream);
        }, 'rcpa-estimates.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    private function visible(Request $request, bool $register = false): Builder
    {
        $actor = $request->user();
        abort_unless($this->rcpa->canUseFieldData($actor) || ($register && $actor->can('viewAny', RcpaObservation::class)), 403);

        return RcpaObservation::query()->forRegister($actor);
    }

    private function filtered(Builder $query, array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));

        return $query->when($filters['from'] ?? null, fn (Builder $query, string $date): Builder => $query->where('observed_on', '>=', $date))
            ->when($filters['to'] ?? null, fn (Builder $query, string $date): Builder => $query->where('observed_on', '<=', $date))
            ->when($search !== '', fn (Builder $query): Builder => $query->where(fn (Builder $match): Builder => $match
                ->whereHas('chemist', fn (Builder $chemist): Builder => $chemist->whereLike('name', '%'.$search.'%'))
                ->orWhereHas('itemMaster', fn (Builder $item): Builder => $item->whereLike('item_name', '%'.$search.'%')->orWhereLike('item_code', '%'.$search.'%'))));
    }

    private function summary(RcpaObservation $record): array
    {
        return [
            ...$record->only(['id', 'chemist_id', 'chemist_tier', 'item_master_id', 'stock_status', 'doctor_rx_share', 'offtake_quantity', 'offtake_days', 'data_flag']),
            'observed_on' => $record->observed_on->toDateString(),
            'chemist' => $record->chemist?->name,
            'item' => $record->itemMaster?->item_name,
        ];
    }
}

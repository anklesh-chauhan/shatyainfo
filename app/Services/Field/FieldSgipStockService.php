<?php

namespace App\Services\Field;

use App\Enums\SgipStockSource;
use App\Models\InventoryStock;
use App\Models\ItemMaster;
use App\Models\SampleIssue;
use App\Models\SgipDistribution;
use App\Models\User;
use App\Models\VisitPreference;

class FieldSgipStockService
{
    public function snapshot(User $user): array
    {
        $preferences = VisitPreference::current();
        $sources = [];
        if ($preferences->sgip_stock_source === SgipStockSource::Headquarters && $preferences->sgip_hq_location_id) {
            $sources[] = ['sample_issue_id' => null, 'location_id' => $preferences->sgip_hq_location_id, 'label' => 'HQ stock'];
        } else {
            $employee = $user->employee?->id;
            if ($employee) {
                $sources = SampleIssue::query()->whereNotNull('posted_at')
                    ->whereHas('sampleRequest', fn ($q) => $q->where('employee_id', $employee))
                    ->orderBy('id')->get()->map(fn ($issue) => [
                        'sample_issue_id' => $issue->id, 'location_id' => $issue->to_location_id,
                        'label' => $issue->document_number,
                    ])->all();
            }
        }
        foreach ($sources as &$source) {
            $source['stock'] = InventoryStock::query()->where('location_master_id', $source['location_id'])
                ->whereHas('item', fn ($q) => $q->whereNotNull('item_type'))->orderBy('item_master_id')->get()
                ->map(fn ($stock) => ['item_master_id' => $stock->item_master_id,
                    'available' => max(0, (float) $stock->quantity_on_hand - (float) $stock->quantity_reserved)])->all();
        }
        unset($source);

        return ['snapshot_at' => now()->toIso8601String(), 'source_mode' => $preferences->sgip_stock_source?->value,
            'sources' => $sources,
            'items' => ItemMaster::query()->whereNotNull('item_type')->orderBy('id')
                ->get(['id', 'item_name', 'item_code', 'item_type', 'selling_price'])->toArray()];
    }

    public function assignSourceAndValidate(User $user, SgipDistribution $distribution): void
    {
        $preferences = VisitPreference::current();
        abort_unless($preferences->sgip_stock_source instanceof SgipStockSource, 422, 'Configure the sample stock source before submitting.');
        abort_if($distribution->inventory_reserved_at !== null, 409, 'This distribution already holds a stock reservation.');
        if ($preferences->sgip_stock_source === SgipStockSource::Headquarters) {
            $location = $preferences->sgip_hq_location_id;
        } else {
            $issue = SampleIssue::query()->with('sampleRequest')->find($distribution->sample_issue_id);
            abort_unless($issue && $issue->isPosted() && (int) $issue->sampleRequest?->employee_id === (int) $user->employee?->id,
                422, 'Select a posted sample issue allocated to this employee.');
            $location = $issue->to_location_id;
        }
        abort_unless($location, 422, 'No sample stock source is configured.');
        foreach ($distribution->items()->with('item')->orderBy('item_master_id')->get() as $line) {
            abort_unless($line->item?->item_type !== null, 422, 'Only classified sample, gift or promotional items can be distributed.');
            $stock = InventoryStock::query()->where('location_master_id', $location)->where('item_master_id', $line->item_master_id)->lockForUpdate()->first();
            $available = $stock ? (float) $stock->quantity_on_hand - (float) $stock->quantity_reserved : 0;
            abort_if((float) $line->quantity > $available, 409, 'Sample stock changed or is insufficient. Refresh the ledger and reconcile the captured distribution.');
            $stock->forceFill(['quantity_reserved' => (float) $stock->quantity_reserved + (float) $line->quantity,
                'quantity_available' => $available - (float) $line->quantity])->save();
        }
        $distribution->inventory_source_location_id = $location;
        $distribution->inventory_reserved_at = now();
    }

    public function release(SgipDistribution $distribution): void
    {
        if (! $distribution->inventory_reserved_at) {
            return;
        }
        foreach ($distribution->items()->orderBy('item_master_id')->get() as $line) {
            $stock = InventoryStock::query()->where('location_master_id', $distribution->inventory_source_location_id)
                ->where('item_master_id', $line->item_master_id)->lockForUpdate()->firstOrFail();
            $reserved = max(0, (float) $stock->quantity_reserved - (float) $line->quantity);
            $stock->forceFill(['quantity_reserved' => $reserved,
                'quantity_available' => (float) $stock->quantity_on_hand - $reserved])->save();
        }
        $distribution->inventory_reserved_at = null;
    }
}

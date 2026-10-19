<?php

namespace App\Services;

use App\Models\AccountMaster;

class AccountAssignmentService
{
    public function capture(AccountMaster $account): void
    {
        $account->getConnection()->transaction(function () use ($account): void {
            $record = AccountMaster::withTrashed()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $patchIds = $record->patches()->orderBy('patches.id')->pluck('patches.id')->map(fn ($id): int => (int) $id)->all();
            $current = $record->assignmentHistory()->whereNull('effective_to')->latest('id')->first();
            if ($current && (int) $current->owner_id === (int) $record->owner_id && (int) $current->territory_id === (int) $record->territory_id && $current->patch_ids === $patchIds) {
                return;
            }
            $time = now();
            if ($current) {
                $current->update(['effective_to' => $time]);
            }
            $record->assignmentHistory()->create(['owner_id' => $record->owner_id, 'territory_id' => $record->territory_id, 'patch_ids' => $patchIds, 'effective_from' => $time, 'changed_by' => auth()->id()]);
        });
    }
}

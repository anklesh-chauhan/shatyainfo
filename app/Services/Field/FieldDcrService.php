<?php

namespace App\Services\Field;

use App\Models\ApprovalSetting;
use App\Models\SalesDcr;
use App\Models\User;
use App\Models\VisitPreference;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FieldDcrService
{
    public function forDate(User $user, ?string $date = null): SalesDcr
    {
        return $this->getOrCreateForUser($user, $date ?? today()->toDateString());
    }

    public function findOwned(User $user, int $dcrId): SalesDcr
    {
        $dcr = SalesDcr::query()
            ->with($this->defaultRelations())
            ->find($dcrId);

        if (! $dcr || (int) $dcr->user_id !== (int) $user->id) {
            abort(404, 'DCR not found.');
        }

        return $dcr;
    }

    public function submit(User $user, SalesDcr $dcr): SalesDcr
    {
        $this->assertOwned($user, $dcr);
        $this->assertEditable($dcr);

        if ($dcr->visits()->count() === 0) {
            throw ValidationException::withMessages([
                'dcr' => 'Add at least one visit before submitting the DCR.',
            ]);
        }

        DB::transaction(function () use ($dcr): void {
            $dcr = SalesDcr::query()->lockForUpdate()->findOrFail($dcr->id);
            $this->assertEditable($dcr);
            if (ApprovalSetting::moduleRequiresApproval(SalesDcr::class)) {
                try {
                    $dcr->startApproval();
                } catch (\LogicException $exception) {
                    throw ValidationException::withMessages(['approval' => $exception->getMessage()]);
                }
            } else {
                $dcr->prepareOperationalApproval();
                $dcr->forceFill([
                    'approval_status' => 'approved',
                    'submitted_at' => now(),
                    'approved_at' => now(),
                    'approved_by' => null,
                    'total_expense_approved' => $dcr->total_expense,
                    'total_expense_rejected' => 0,
                ])->saveQuietly();
            }
        });

        return $this->findOwned($user, $dcr->id);
    }

    public function assertOwned(User $user, SalesDcr $dcr): void
    {
        if ((int) $dcr->user_id !== (int) $user->id) {
            abort(404, 'DCR not found.');
        }
    }

    public function assertEditable(SalesDcr $dcr): void
    {
        $dcr->assertApprovalDocumentEditable();
        if (! in_array($dcr->approval_status, ['draft', 'rejected'], true)) {
            throw ValidationException::withMessages([
                'dcr' => 'Only draft or rejected DCRs can be modified.',
            ]);
        }

        VisitPreference::current()->assertDcrDateIsReportable($dcr->dcr_date, 'dcr');
    }

    private function getOrCreateForUser(User $user, Carbon|string $date): SalesDcr
    {
        $dcrDate = $date instanceof Carbon ? $date->toDateString() : Carbon::parse($date)->toDateString();
        $preference = VisitPreference::current();

        $existing = SalesDcr::query()
            ->where('user_id', $user->id)
            ->whereDate('dcr_date', $dcrDate)
            ->first();

        if ($existing) {
            return $existing->load($this->defaultRelations());
        }

        $preference->assertDcrDateIsReportable($dcrDate);

        $dcr = SalesDcr::query()->firstOrCreate(
            [
                'user_id' => $user->id,
                'dcr_date' => $dcrDate,
            ],
            [
                'approval_status' => 'draft',
                'territory_id' => $user->territory_id ?? null,
            ],
        );

        return $dcr->load($this->defaultRelations());
    }

    /**
     * @return array<int, mixed>
     */
    private function defaultRelations(): array
    {
        return [
            'territory',
            'expenses.expenseType',
            'visits' => fn ($query) => $query->select([
                'id',
                'document_number',
                'sales_dcr_id',
                'visit_date',
                'visit_status',
                'territory_id',
                'patch_id',
            ]),
        ];
    }
}

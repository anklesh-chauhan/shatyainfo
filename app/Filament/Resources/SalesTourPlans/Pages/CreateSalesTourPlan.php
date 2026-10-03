<?php

namespace App\Filament\Resources\SalesTourPlans\Pages;

use App\Filament\Resources\SalesTourPlans\SalesTourPlanResource;
use App\Models\SalesTourPlan;
use App\Models\User;
use App\Models\VisitPreference;
use App\Services\PositionService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CreateSalesTourPlan extends CreateRecord
{
    protected static string $resource = SalesTourPlanResource::class;

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            $this->getCancelFormAction(),
        ];
    }

    public function createPlanForSelectedMonth(?string $month, mixed $userId): void
    {
        $authUser = Auth::user();

        if (! $authUser instanceof User || ! preg_match('/^\d{4}-\d{2}$/', (string) $month)) {
            return;
        }

        abort_unless($authUser->can('create', SalesTourPlan::class), 403);

        if (! VisitPreference::current()->allowsTourPlanMonth($month)) {
            $this->data['month'] = null;

            Notification::make()
                ->title('That month is outside the planning window')
                ->body('An administrator sets how many months ahead a tour plan can be created.')
                ->danger()
                ->send();

            return;
        }

        $ownerId = (int) ($userId ?: $authUser->id);
        $owner = $this->resolveOwner($authUser, $ownerId);

        if (! $owner) {
            Notification::make()
                ->title('Select a sales employee')
                ->danger()
                ->send();

            return;
        }

        $plan = DB::transaction(function () use ($owner, $month): SalesTourPlan {
            User::query()->whereKey($owner->id)->lockForUpdate()->first();

            $existing = SalesTourPlan::query()
                ->where('user_id', $owner->id)
                ->where('month', 'like', $month.'%')
                ->first();

            if ($existing) {
                return $existing;
            }

            $plan = new SalesTourPlan([
                'user_id' => $owner->id,
                'approval_status' => 'draft',
            ]);
            $plan->month = $month;
            $plan->save();

            $territoryIds = PositionService::getTerritoryIdsForUser($owner);
            $territoryId = count($territoryIds) === 1 ? $territoryIds[0] : null;

            $date = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
            $end = $date->copy()->endOfMonth();

            while ($date->lte($end)) {
                $plan->details()->create([
                    'date' => $date->toDateString(),
                    'territory_id' => $territoryId,
                    'joint_with' => [],
                    'visit_purpose_ids' => [],
                ]);

                $date->addDay();
            }

            return $plan;
        });

        Notification::make()
            ->title($plan->wasRecentlyCreated ? 'Tour plan created' : 'Tour plan already exists')
            ->body($plan->wasRecentlyCreated
                ? 'Every day of the selected month has been added.'
                : 'Opened the existing plan for this month.')
            ->success()
            ->send();

        $this->skipRender();

        $this->redirect(SalesTourPlanResource::getUrl('edit', ['record' => $plan]), navigate: true);
    }

    private function resolveOwner(User $authUser, int $ownerId): ?User
    {
        if (! $authUser->hasRole('Super Admin')) {
            $visibleUserIds = PositionService::getVisibleUserIdsFor($authUser);

            if (! $visibleUserIds->contains($ownerId)) {
                return null;
            }
        }

        return User::query()->find($ownerId);
    }
}

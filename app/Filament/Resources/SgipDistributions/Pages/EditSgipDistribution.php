<?php

namespace App\Filament\Resources\SgipDistributions\Pages;

use App\Filament\Resources\SgipDistributions\SgipDistributionResource;
use App\Models\ApprovalSetting;
use App\Models\SgipDistribution;
use App\Services\SGIPComplianceService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;
use Throwable;

class EditSgipDistribution extends EditRecord
{
    protected static string $resource = SgipDistributionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('submit')
                ->label('Submit')
                ->color('primary')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->record->approval_status === 'draft')
                ->action(function (): void {
                    if (! $this->record->items()->exists()) {
                        Notification::make()
                            ->title('Add at least one item before submitting.')
                            ->danger()
                            ->send();

                        return;
                    }

                    try {
                        SGIPComplianceService::validate($this->record, true);
                        $this->record->update(['approval_status' => 'submitted']);
                        $this->refreshFormData(['approval_status']);

                        Notification::make()
                            ->title('SGIP distribution submitted')
                            ->success()
                            ->send();
                    } catch (ValidationException $exception) {
                        Notification::make()
                            ->title('Unable to submit SGIP distribution')
                            ->body(collect($exception->errors())->flatten()->implode(' '))
                            ->danger()
                            ->send();
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->title('Unable to submit SGIP distribution')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Action::make('approve')
                ->label(fn (): string => ApprovalSetting::moduleRequiresApproval(SgipDistribution::class) ? 'Approve & Deduct Stock' : 'Post & Deduct Stock')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->record->approval_status === 'submitted' && ! $this->record->isInventoryPosted())
                ->action(function (): void {
                    try {
                        SGIPComplianceService::validate($this->record, true);
                        $this->record->approve();
                        $this->refreshFormData(['approval_status', 'inventory_posted_at']);

                        Notification::make()
                            ->title('SGIP posted and stock deducted')
                            ->success()
                            ->send();
                    } catch (ValidationException $exception) {
                        Notification::make()
                            ->title('Unable to post SGIP distribution')
                            ->body(collect($exception->errors())->flatten()->implode(' '))
                            ->danger()
                            ->send();
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->title('Unable to post SGIP distribution')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            DeleteAction::make()
                ->visible(fn (): bool => $this->record->approval_status === 'draft'),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    protected function canEdit(): bool
    {
        return $this->record->approval_status === 'draft';
    }
}

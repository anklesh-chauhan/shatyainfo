<?php

namespace App\Filament\Resources\InventoryAdjustments\Pages;

use App\Filament\Actions\ApprovalAction;
use App\Filament\Resources\InventoryAdjustments\InventoryAdjustmentResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Throwable;

class EditInventoryAdjustment extends EditRecord
{
    protected static string $resource = InventoryAdjustmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ApprovalAction::make(),
            Action::make('post')
                ->label('Post Adjustment')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (): bool => ! $this->record->isPosted() && $this->record->canProceedAfterApproval())
                ->action(function (): void {
                    try {
                        $this->record->post();

                        Notification::make()
                            ->title('Adjustment posted')
                            ->body('Stock has been updated successfully.')
                            ->success()
                            ->send();

                        $this->refreshFormData(['status', 'posted_at', 'posted_by']);
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->title('Unable to post adjustment')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            DeleteAction::make()
                ->visible(fn (): bool => $this->record->isEditable()),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (! $this->record->isEditable()) {
            Notification::make()
                ->title('Adjustment is locked')
                ->body('Posted adjustments cannot be edited.')
                ->danger()
                ->send();

            $this->halt();
        }

        return $data;
    }

    protected function canEdit(): bool
    {
        return ! $this->record->isPosted();
    }
}

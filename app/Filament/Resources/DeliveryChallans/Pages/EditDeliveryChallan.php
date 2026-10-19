<?php

namespace App\Filament\Resources\DeliveryChallans\Pages;

use App\Filament\Actions\ApprovalAction;
use App\Filament\Resources\DeliveryChallans\DeliveryChallanResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Throwable;

class EditDeliveryChallan extends EditRecord
{
    protected static string $resource = DeliveryChallanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ApprovalAction::make(),
            Action::make('post')
                ->label('Post to Stock')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Post delivery challan to inventory?')
                ->modalDescription('This will reduce stock at the dispatch location and update delivered quantities on the linked sales invoice.')
                ->visible(fn (): bool => ! $this->record->isPosted() && $this->record->canProceedAfterApproval())
                ->action(function (): void {
                    try {
                        $this->record->post();

                        Notification::make()
                            ->title('Delivery challan posted')
                            ->body('Stock has been updated successfully.')
                            ->success()
                            ->send();

                        $this->refreshFormData(['status', 'posted_at', 'posted_by']);
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->title('Unable to post delivery challan')
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
                ->title('Delivery challan is locked')
                ->body('Posted delivery challans cannot be edited.')
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

<?php

namespace App\Filament\Resources\GoodsReceiptNotes\Pages;

use App\Filament\Actions\ApprovalAction;
use App\Filament\Resources\GoodsReceiptNotes\GoodsReceiptNoteResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Throwable;

class EditGoodsReceiptNote extends EditRecord
{
    protected static string $resource = GoodsReceiptNoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ApprovalAction::make(),
            Action::make('post')
                ->label('Post to Stock')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Post GRN to inventory?')
                ->modalDescription('This will increase stock at the receiving location and update linked purchase order quantities.')
                ->visible(fn (): bool => ! $this->record->isPosted() && $this->record->canProceedAfterApproval())
                ->action(function (): void {
                    try {
                        $this->record->post();

                        Notification::make()
                            ->title('GRN posted')
                            ->body('Stock has been updated successfully.')
                            ->success()
                            ->send();

                        $this->refreshFormData(['status', 'posted_at', 'posted_by']);
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->title('Unable to post GRN')
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
                ->title('GRN is locked')
                ->body('Posted GRNs cannot be edited.')
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

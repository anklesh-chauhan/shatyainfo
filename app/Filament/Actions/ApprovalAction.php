<?php

namespace App\Filament\Actions;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class ApprovalAction extends Action
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Send for Approval');
        $this->icon('heroicon-o-arrow-up-tray');
        $this->color('primary');

        $this->visible(fn ($record) => method_exists($record, 'canSendForApproval')
            && $record->canSendForApproval()
        );

        $this->action(function ($record, Component $livewire) {
            if (! method_exists($record, 'startApprovalFromRules')) {
                Notification::make()
                    ->title('Model does not support approval.')
                    ->danger()
                    ->send();

                return;
            }

            if (method_exists($record, 'canStartApproval') && ! $record->canStartApproval()) {
                Notification::make()
                    ->title('Approval workflow is not available for this record.')
                    ->warning()
                    ->send();

                return;
            }

            try {
                $record->startApprovalFromRules();
            } catch (\LogicException|ValidationException $exception) {
                Notification::make()->title('Unable to submit for approval')->body($exception->getMessage())->danger()->send();

                return;
            }

            $livewire->dispatch('refresh-sidebar');

            Notification::make()
                ->title('Approval workflow started')
                ->success()
                ->send();
        });
    }

    public static function make(?string $name = null): static
    {
        // Provide default value if not given
        $name ??= 'send_for_approval';

        return parent::make($name);
    }
}

<?php

namespace App\Filament\Resources\FollowUps\Pages;

use App\Filament\Resources\FollowUps\FollowUpResource;
use App\Models\FollowUp;
use App\Services\SalespersonAi\FollowUpScriptService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFollowUp extends EditRecord
{
    protected static string $resource = FollowUpResource::class;

    public function getSubheading(): ?string
    {
        $record = $this->getRecord();

        if (! $record instanceof FollowUp) {
            return null;
        }

        return app(FollowUpScriptService::class)->forFollowUp($record)['summary'];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('previous')
                ->label('')
                ->icon('heroicon-o-arrow-left')
                ->url(fn () => $this->getPreviousRecordUrl())
                ->disabled(is_null($this->getPreviousRecordId())),

            Action::make('next')
                ->label('')
                ->icon('heroicon-o-arrow-right')
                ->url(fn () => $this->getNextRecordUrl())
                ->disabled(is_null($this->getNextRecordId())),

            DeleteAction::make(),
        ];
    }

    private function getPreviousRecordId(): ?int
    {
        if (! $this->record) {
            return null;
        }

        return FollowUp::where('id', '<', $this->record->id)
            ->orderBy('id', 'desc')
            ->value('id');
    }

    private function getNextRecordId(): ?int
    {
        if (! $this->record) {
            return null;
        }

        return FollowUp::where('id', '>', $this->record->id)
            ->orderBy('id')
            ->value('id');
    }

    private function getPreviousRecordUrl(): ?string
    {
        if ($previousId = $this->getPreviousRecordId()) {
            return FollowUpResource::getUrl('edit', ['record' => $previousId]);
        }

        return null;
    }

    private function getNextRecordUrl(): ?string
    {
        if ($nextId = $this->getNextRecordId()) {
            return FollowUpResource::getUrl('edit', ['record' => $nextId]);
        }

        return null;
    }
}

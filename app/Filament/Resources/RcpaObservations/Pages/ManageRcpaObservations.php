<?php

namespace App\Filament\Resources\RcpaObservations\Pages;

use App\Filament\Resources\RcpaObservations\RcpaObservationResource;
use App\Services\Field\FieldRcpaService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ManageRecords;

class ManageRcpaObservations extends ManageRecords
{
    protected static string $resource = RcpaObservationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('due')->label('Chemist due list')->schema([
                Textarea::make('due_list')->label('Current rolling 7/14-day frequency status')
                    ->default(fn () => collect(app(FieldRcpaService::class)->options(auth()->user())['chemists'])
                        ->map(fn ($chemist) => $chemist['name'].' · Tier '.$chemist['tier'].' · '.($chemist['is_due'] ? 'DUE' : 'Current').' · next '.($chemist['next_due_on'] ?? 'now'))->implode("\n"))->rows(20)->disabled()->dehydrated(false),
            ])->modalSubmitAction(false),
            Action::make('export')->label('Export estimates')->url(fn () => route('sfa.rcpa.export')),
        ];
    }
}

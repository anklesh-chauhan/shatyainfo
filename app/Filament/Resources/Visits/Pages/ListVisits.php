<?php

namespace App\Filament\Resources\Visits\Pages;

use App\Filament\Resources\Visits\VisitResource;
use App\Models\AccountMaster;
use App\Models\Visit;
use App\Services\Field\FieldPlanningService;
use App\Services\Field\FieldVisitService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Str;

class ListVisits extends ListRecords
{
    protected static string $resource = VisitResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('direct_call')->label('Record direct visit')
                ->visible(fn (): bool => auth()->user()->can('create', Visit::class)
                    && app(FieldPlanningService::class)->capabilities()['supports_direct_calls']
                    && app(FieldPlanningService::class)->capabilities()['allows_unplanned_calls'])
                ->schema([
                    Hidden::make('client_call_id')->default(fn () => (string) Str::uuid()),
                    Select::make('company_id')->label('Doctor or chemist')->searchable()->required()
                        ->getSearchResultsUsing(fn (string $search): array => AccountMaster::query()->applyVisibility('AccountMaster')->where('name', 'like', '%'.$search.'%')->limit(50)->pluck('name', 'id')->all())
                        ->getOptionLabelUsing(fn ($value): ?string => AccountMaster::query()->applyVisibility('AccountMaster')->find($value)?->name),
                    DatePicker::make('date')->default(today())->maxDate(today())->required(),
                    Textarea::make('unplanned_reason')->label('Reason for unplanned call')->maxLength(2000),
                ])
                ->action(function (array $data): void {
                    $visit = app(FieldVisitService::class)->openDirect(auth()->user(), $data);
                    $this->redirect(VisitResource::getUrl('edit', ['record' => $visit]));
                }),
            CreateAction::make(),
        ];
    }
}

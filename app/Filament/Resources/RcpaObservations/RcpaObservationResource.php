<?php

namespace App\Filament\Resources\RcpaObservations;

use App\Filament\Resources\RcpaObservations\Pages\ManageRcpaObservations;
use App\Models\AccountMaster;
use App\Models\RcpaObservation;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class RcpaObservationResource extends Resource
{
    protected static ?string $model = RcpaObservation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'SFA Operations';

    public static function canViewAny(): bool
    {
        return (bool) (auth()->user()?->can('Capture:Rcpa') || auth()->user()?->can('View:Rcpa'));
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        if (auth()->user()->can('View:Rcpa')) {
            return $query->whereIn('chemist_id', AccountMaster::query()->applyVisibility('AccountMaster')->select('id'));
        }

        return $query->where('user_id', auth()->id());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                //
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('observed_on')->date()->sortable(),
                TextColumn::make('chemist.name')->searchable(),
                TextColumn::make('chemist_tier'),
                TextColumn::make('itemMaster.item_name'),
                TextColumn::make('stock_status'),
                TextColumn::make('doctor_rx_share')->suffix('%'),
                TextColumn::make('offtake_quantity')->label('Estimated base units'),
                TextColumn::make('offtake_days')->label('Days'),
                TextColumn::make('data_flag')->formatStateUsing(fn ($state) => '['.$state.']')->badge(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('inspect')->schema([
                    Textarea::make('observations')->label('Competitor observations [ESTIMATED_FIELD]')->default(fn (RcpaObservation $record) => json_encode($record->competitors, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))->rows(15)->disabled()->dehydrated(false),
                ])->modalSubmitAction(false),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageRcpaObservations::route('/'),
        ];
    }
}

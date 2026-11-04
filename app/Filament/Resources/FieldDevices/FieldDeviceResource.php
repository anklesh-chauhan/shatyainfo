<?php

namespace App\Filament\Resources\FieldDevices;

use App\Filament\Resources\FieldDevices\Pages\ManageFieldDevices;
use App\Models\FieldDevice;
use App\Services\Field\FieldDeviceService;
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

class FieldDeviceResource extends Resource
{
    protected static ?string $model = FieldDevice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDevicePhoneMobile;

    protected static string|\UnitEnum|null $navigationGroup = 'SFA Operations';

    protected static ?string $modelLabel = 'Field device';

    public static function canViewAny(): bool
    {
        return auth()->check();
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
        $query = parent::getEloquentQuery()->with(['user:id,name', 'closedBy:id,name']);
        if (! auth()->user()->can(FieldDeviceService::REVIEW_PERMISSION)) {
            $query->where('user_id', auth()->id());
        }

        return $query;
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
            ->defaultSort('reported_at', 'desc')
            ->columns([
                TextColumn::make('user.name')->label('Representative')->sortable(),
                TextColumn::make('name')->label('Device'),
                TextColumn::make('platform'),
                TextColumn::make('status')->badge()->color(fn (string $state): string => match ($state) {
                    'active' => 'success', 'closed' => 'gray', default => 'warning',
                }),
                TextColumn::make('pending_count')->label('Unsynchronized records')->numeric(),
                TextColumn::make('pending_dates')->label('Dates with pending work')
                    ->state(fn (FieldDevice $record): string => collect($record->pending_dates ?? [])->map(fn (array $entry) => $entry['date'].' ('.$entry['count'].')')->implode(', ') ?: '—')->wrap(),
                TextColumn::make('reported_at')->label('Last report')->dateTime()->sortable(),
                TextColumn::make('closed_at')->dateTime(),
                TextColumn::make('closedBy.name')->label('Closed / retired by'),
                TextColumn::make('close_reason')->wrap()->limit(120),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('retire')->label('Retire device')
                    ->visible(fn (FieldDevice $record) => app(FieldDeviceService::class)->canRetire(auth()->user(), $record))
                    ->modalDescription('Retiring stops this device from blocking DCR submission. Any unsynchronized records it still holds are not recovered by this action; if the device reconnects later, its records are revalidated and enter late sync review when their dates are closed.')
                    ->schema([
                        Textarea::make('reason')->label('Why this device is retired and what happened to its pending work')->required()->minLength(5)->maxLength(2000),
                    ])->action(fn (FieldDevice $record, array $data) => app(FieldDeviceService::class)->retire(auth()->user(), $record, $data['reason'])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageFieldDevices::route('/'),
        ];
    }
}

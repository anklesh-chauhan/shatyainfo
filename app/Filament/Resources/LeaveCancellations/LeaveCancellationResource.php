<?php

namespace App\Filament\Resources\LeaveCancellations;

use App\Filament\Resources\LeaveCancellations\Pages\ManageLeaveCancellations;
use App\Models\LeaveCancellation;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LeaveCancellationResource extends Resource
{
    protected static ?string $model = LeaveCancellation::class;

    protected static string|\UnitEnum|null $navigationGroup = 'HR';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('employee.full_name')->label('Employee'),
            TextColumn::make('leaveApplication.from_date')->date(),
            TextColumn::make('leaveApplication.to_date')->date(),
            TextColumn::make('approval_status')->badge(),
            TextColumn::make('reason'),
            TextColumn::make('applied_at')->dateTime(),
        ])->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageLeaveCancellations::route('/')];
    }
}

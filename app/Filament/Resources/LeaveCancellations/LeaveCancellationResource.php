<?php

namespace App\Filament\Resources\LeaveCancellations;

use App\Filament\Clusters\HR\LeaveManagementCluster;
use App\Filament\Resources\LeaveCancellations\Pages\ManageLeaveCancellations;
use App\Models\LeaveCancellation;
use App\Services\OptionalModuleService;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LeaveCancellationResource extends Resource
{
    public static function shouldRegisterNavigation(): bool
    {
        return parent::shouldRegisterNavigation()
            && app(OptionalModuleService::class)->navigationEnabled('LeaveCancellation');
    }

    protected static ?string $cluster = LeaveManagementCluster::class;

    protected static ?string $model = LeaveCancellation::class;

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

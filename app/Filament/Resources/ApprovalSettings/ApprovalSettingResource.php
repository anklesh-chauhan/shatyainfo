<?php

namespace App\Filament\Resources\ApprovalSettings;

use App\Filament\Clusters\GlobalConfiguration\OperationalConfigCluster;
use App\Filament\Resources\ApprovalSettings\Pages\CreateApprovalSetting;
use App\Filament\Resources\ApprovalSettings\Pages\EditApprovalSetting;
use App\Filament\Resources\ApprovalSettings\Pages\ListApprovalSettings;
use App\Filament\Resources\ApprovalSettings\Schemas\ApprovalSettingForm;
use App\Filament\Resources\ApprovalSettings\Tables\ApprovalSettingsTable;
use App\Filament\Resources\BaseResource;
use App\Models\ApprovalSetting;
use App\Traits\HasSafeGlobalSearch;
use BackedEnum;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ApprovalSettingResource extends BaseResource
{
    use HasSafeGlobalSearch;

    protected static ?string $model = ApprovalSetting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $cluster = OperationalConfigCluster::class;

    protected static bool $isGloballySearchable = false;

    protected static ?string $recordTitleAttribute = null;

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return ApprovalSettingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ApprovalSettingsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    // Removed 'create' page to prevent creation of new Approval Settings via Filament UI
    public static function canCreate(): bool
    {
        return false; // disables create page & button
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApprovalSettings::route('/'),
            // 'create' => CreateApprovalSetting::route('/create'),
            'edit' => EditApprovalSetting::route('/{record}/edit'),
        ];
    }
}

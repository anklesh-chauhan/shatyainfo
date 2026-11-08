<?php

namespace App\Filament\Resources\SalesTourPlans;

use App\Filament\Resources\BaseResource;
use App\Filament\Resources\SalesTourPlans\Pages\CreateSalesTourPlan;
use App\Filament\Resources\SalesTourPlans\Pages\EditSalesTourPlan;
use App\Filament\Resources\SalesTourPlans\Pages\ListSalesTourPlans;
use App\Filament\Resources\SalesTourPlans\Schemas\SalesTourPlanForm;
use App\Filament\Resources\SalesTourPlans\Tables\SalesTourPlansTable;
use App\Models\SalesTourPlan;
use App\Traits\HasApprovalWorkflow;
use App\Traits\HasSafeGlobalSearch;
use App\Traits\HasVisibilityScope;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class SalesTourPlanResource extends BaseResource
{
    protected static ?int $navigationSort = 10;

    protected static string|\UnitEnum|null $navigationGroup = 'Field Operations';

    use HasApprovalWorkflow, HasSafeGlobalSearch, HasVisibilityScope;

    protected static ?string $model = SalesTourPlan::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    // Added a label for better readability in the navigation
    protected static ?string $navigationLabel = 'Tour Plans';

    protected static ?string $recordTitleAttribute = null;

    public static function form(Schema $schema): Schema
    {
        return SalesTourPlanForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SalesTourPlansTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSalesTourPlans::route('/'),
            'create' => CreateSalesTourPlan::route('/create'),
            'edit' => EditSalesTourPlan::route('/{record}/edit'),
        ];
    }
}

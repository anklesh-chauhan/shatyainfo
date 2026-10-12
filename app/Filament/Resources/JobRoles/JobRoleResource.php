<?php

namespace App\Filament\Resources\JobRoles;

use App\Filament\Clusters\HR\EmployeeManagementCluster;
use App\Filament\Resources\JobRoles\Pages\CreateJobRole;
use App\Filament\Resources\JobRoles\Pages\EditJobRole;
use App\Filament\Resources\JobRoles\Pages\ListJobRoles;
use App\Filament\Resources\JobRoles\Schemas\JobRoleForm;
use App\Filament\Resources\JobRoles\Tables\JobRolesTable;
use App\Models\JobRole;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class JobRoleResource extends Resource
{
    protected static ?string $model = JobRole::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'code'];
    }

    protected static ?string $cluster = EmployeeManagementCluster::class;

    protected static ?string $navigationLabel = 'Job Roles';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return JobRoleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return JobRolesTable::configure($table);
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
            'index' => ListJobRoles::route('/'),
            'create' => CreateJobRole::route('/create'),
            'edit' => EditJobRole::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}

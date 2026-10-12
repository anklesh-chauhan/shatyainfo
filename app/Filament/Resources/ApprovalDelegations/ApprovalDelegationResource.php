<?php

namespace App\Filament\Resources\ApprovalDelegations;

use App\Filament\Clusters\GlobalConfiguration\OperationalConfigCluster;
use App\Filament\Resources\ApprovalDelegations\Pages\CreateApprovalDelegation;
use App\Filament\Resources\ApprovalDelegations\Pages\EditApprovalDelegation;
use App\Filament\Resources\ApprovalDelegations\Pages\ListApprovalDelegations;
use App\Filament\Resources\ApprovalDelegations\Schemas\ApprovalDelegationForm;
use App\Filament\Resources\ApprovalDelegations\Tables\ApprovalDelegationsTable;
use App\Models\ApprovalDelegation;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ApprovalDelegationResource extends Resource
{
    protected static ?string $model = ApprovalDelegation::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedUserPlus;

    protected static ?string $cluster = OperationalConfigCluster::class;

    protected static ?string $recordTitleAttribute = 'module';

    protected static ?int $navigationSort = 11;

    public static function form(Schema $schema): Schema
    {
        return ApprovalDelegationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ApprovalDelegationsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApprovalDelegations::route('/'),
            'create' => CreateApprovalDelegation::route('/create'),
            'edit' => EditApprovalDelegation::route('/{record}/edit'),
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

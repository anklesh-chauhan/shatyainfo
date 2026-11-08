<?php

namespace App\Filament\Resources\Approvals;

use App\Filament\Resources\Approvals\Pages\CreateApproval;
use App\Filament\Resources\Approvals\Pages\EditApproval;
use App\Filament\Resources\Approvals\Pages\ListApprovals;
use App\Filament\Resources\Approvals\Pages\ViewApproval;
use App\Filament\Resources\Approvals\Schemas\ApprovalForm;
use App\Filament\Resources\Approvals\Schemas\ApprovalInfolist;
use App\Filament\Resources\Approvals\Tables\ApprovalsTable;
use App\Models\Approval;
use App\Traits\HasRelatedGlobalSearch;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ApprovalResource extends Resource
{
    protected static ?int $navigationSort = 50;

    protected static ?string $navigationLabel = 'Approval Register';

    protected static string|\UnitEnum|null $navigationGroup = 'My Workspace';

    use HasRelatedGlobalSearch;

    protected static ?string $model = Approval::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    protected static ?string $recordTitleAttribute = 'module';

    protected static array $relatedGlobalSearchAttributes = [
        'module',
        'approval_status',
        'requester.name',
        'requester.email',
    ];

    public static function form(Schema $schema): Schema
    {
        return ApprovalForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ApprovalInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ApprovalsTable::configure($table);
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
            'index' => ListApprovals::route('/'),
            'create' => CreateApproval::route('/create'),
            'view' => ViewApproval::route('/{record}'),
            'edit' => EditApproval::route('/{record}/edit'),
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

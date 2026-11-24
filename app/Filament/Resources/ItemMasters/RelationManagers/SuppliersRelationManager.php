<?php

namespace App\Filament\Resources\ItemMasters\RelationManagers;

use App\Filament\Resources\ItemMasters\Tables\ItemRegisterActions;
use App\Models\AccountMaster;
use App\Services\Field\FieldAccountService;
use App\Services\Inventory\ItemRegister;
use App\Traits\CreateAccountMasterTrait;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class SuppliersRelationManager extends RelationManager
{
    use CreateAccountMasterTrait;

    protected static string $relationship = 'suppliers';

    public function form(Schema $schema): Schema
    {
        return $schema->components(self::getCreateAccountMasterTraitFields());
    }

    public function table(Table $table): Table
    {
        $parentId = $this->getOwnerRecord()->id;
        $revision = fn (): string => app(ItemRegister::class)->revision($this->getOwnerRecord()->fresh());

        return $table->recordTitleAttribute('name')->columns([TextColumn::make('name')->searchable()])
            ->modifyQueryUsing(fn (Builder $query) => $query->applyVisibility('AccountMaster')->when(! auth()->user()->can(['ViewAny:AccountMaster', 'View:AccountMaster']), fn (Builder $query) => $query->whereKey([])))
            ->headerActions([
                Action::make('attach')->label('Attach Supplier')->schema([Hidden::make('revision')->default($revision),
                    Select::make('ids')->label('Suppliers')->multiple()->searchable()->required()->options(fn (): array => app(ItemRegister::class)->catalog(auth()->user(), 'suppliers')->pluck('name', 'id')->all()),
                ])->action(fn (array $data) => ItemRegisterActions::run(fn () => app(ItemRegister::class)->relate(auth()->user(), $parentId, 'suppliers', 'attach', $data['ids'], $data['revision']))),
                CreateAction::make()->using(function (array $data, CreateAction $action) use ($parentId, $revision): AccountMaster {
                    $data['type_master_id'] ??= $action->getRawData()['type_master_id'] ?? $action->getRawData()['parent_type_id'] ?? null;

                    return (new AccountMaster)->getConnection()->transaction(function () use ($data, $parentId, $revision): AccountMaster {
                        abort_unless(app(ItemRegister::class)->canChange(auth()->user(), $this->getOwnerRecord()) && auth()->user()->can('Create:AccountMaster'), 403);
                        $before = $revision();
                        $record = app(FieldAccountService::class)->store(auth()->user(), $data);
                        app(ItemRegister::class)->relate(auth()->user(), $parentId, 'suppliers', 'attach', [$record->id], $before);

                        return $record;
                    });
                }),
            ])->recordActions([
                EditAction::make()->using(function (AccountMaster $record, array $data, EditAction $action): AccountMaster {
                    $data['type_master_id'] ??= $action->getRawData()['type_master_id'] ?? $action->getRawData()['parent_type_id'] ?? $record->type_master_id;
                    abort_unless(app(ItemRegister::class)->canChange(auth()->user(), $this->getOwnerRecord()), 403);
                    $visible = app(FieldAccountService::class)->findVisible(auth()->user(), $record->id);

                    return app(FieldAccountService::class)->update(auth()->user(), $visible, $data);
                }),
                Action::make('delete')->requiresConfirmation()->schema([Hidden::make('revision')->default($revision)])
                    ->action(fn (AccountMaster $record, array $data) => ItemRegisterActions::run(fn () => app(ItemRegister::class)->relate(auth()->user(), $parentId, 'suppliers', 'delete', [$record->id], $data['revision']))),
            ])->toolbarActions([BulkAction::make('delete')->requiresConfirmation()->action(fn (Collection $records) => ItemRegisterActions::run(fn () => app(ItemRegister::class)->relate(auth()->user(), $parentId, 'suppliers', 'delete', $records->modelKeys(), $revision())))]);
    }
}

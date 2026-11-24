<?php

namespace App\Filament\Resources\ItemMasters\RelationManagers;

use App\Filament\Resources\ItemMasters\Schemas\ItemRegisterForm;
use App\Filament\Resources\ItemMasters\Tables\ItemRegisterActions;
use App\Models\ItemMaster;
use App\Services\Inventory\ItemRegister;
use App\Traits\ItemMasterTableTrait;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class VariantsRelationManager extends RelationManager
{
    use ItemMasterTableTrait;

    protected static string $relationship = 'variants';

    protected static ?string $title = 'Item Variants';

    public function form(Schema $schema): Schema
    {
        return $schema->components(ItemRegisterForm::fields(variant: true));
    }

    public function table(Table $table): Table
    {
        $parentId = $this->getOwnerRecord()->id;

        return $table->recordTitleAttribute('variant_name')->columns(self::getItemMasterTableTrait())
            ->modifyQueryUsing(fn (Builder $query) => $query->withoutGlobalScopes([SoftDeletingScope::class])->applyVisibility('ItemMaster')->with('approval'))
            ->filters([TrashedFilter::make()])->headerActions([
                CreateAction::make()->fillForm(['item_name' => $this->getOwnerRecord()->item_name])
                    ->authorize(fn (): bool => app(ItemRegister::class)->canChange(auth()->user(), $this->getOwnerRecord()) && auth()->user()->can('Create:ItemMaster'))
                    ->using(fn (array $data): ItemMaster => app(ItemRegister::class)->save(auth()->user(), null, $data, $parentId)),
                Action::make('associate')->schema([
                    Hidden::make('revision')->default(fn (): string => app(ItemRegister::class)->revision($this->getOwnerRecord()->fresh())),
                    Select::make('ids')->label('Items')->multiple()->searchable()->required()
                        ->options(fn (): array => app(ItemRegister::class)->catalog(auth()->user(), 'variants')->whereKeyNot($parentId)->pluck('item_name', 'id')->all()),
                ])->visible(fn (): bool => app(ItemRegister::class)->canChange(auth()->user(), $this->getOwnerRecord()))
                    ->action(fn (array $data) => ItemRegisterActions::run(fn () => app(ItemRegister::class)->relate(auth()->user(), $parentId, 'variants', 'attach', $data['ids'], $data['revision']))),
            ])->recordActions([
                EditAction::make()->fillForm(fn (ItemMaster $record): array => app(ItemRegister::class)->detail(auth()->user(), $record))
                    ->using(fn (ItemMaster $record, array $data): ItemMaster => app(ItemRegister::class)->save(auth()->user(), $record->id, $data, $parentId)),
                ItemRegisterActions::record('dissociate', 'Dissociate', $parentId), ItemRegisterActions::record('delete', 'Delete', $parentId),
                ItemRegisterActions::record('restore', 'Restore', $parentId), ItemRegisterActions::record('force-delete', 'Permanently Delete', $parentId),
            ])->toolbarActions([BulkActionGroup::make([
                ItemRegisterActions::bulk('dissociate', 'Dissociate', $parentId), ItemRegisterActions::bulk('delete', 'Delete', $parentId),
                ItemRegisterActions::bulk('restore', 'Restore', $parentId), ItemRegisterActions::bulk('force-delete', 'Permanently Delete', $parentId),
            ])]);
    }
}

<?php

namespace App\Filament\Resources\ItemMasters\RelationManagers;

use App\Filament\Resources\ItemMasters\Tables\ItemRegisterActions;
use App\Models\Lead;
use App\Services\Inventory\ItemRegister;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class LeadsRelationManager extends RelationManager
{
    protected static string $relationship = 'leads';

    public function form(Schema $schema): Schema
    {
        return $schema->components(self::fields());
    }

    private static function fields(): array
    {
        return [Hidden::make('revision'), TextInput::make('quantity')->numeric()->integer()->minValue(0)->maxValue(2147483647),
            TextInput::make('price')->numeric()->minValue(0)->maxValue(99999999.99)->rule('decimal:0,2')];
    }

    public function table(Table $table): Table
    {
        $parentId = $this->getOwnerRecord()->id;
        $revision = fn (): string => app(ItemRegister::class)->revision($this->getOwnerRecord()->fresh());

        return $table->recordTitleAttribute('reference_code')->columns([
            TextColumn::make('reference_code'), TextColumn::make('accountMaster.name')->label('Customer'), TextColumn::make('pivot.quantity'), TextColumn::make('pivot.price'),
        ])->modifyQueryUsing(fn (Builder $query) => $query->applyVisibility('Lead')->when(! auth()->user()->can(['ViewAny:Lead', 'View:Lead']), fn (Builder $query) => $query->whereKey([])))
            ->headerActions([Action::make('attach')->schema([
                Hidden::make('revision')->default($revision),
                Select::make('ids')->multiple()->required()->searchable()->options(fn (): array => app(ItemRegister::class)->catalog(auth()->user(), 'leads')->pluck('reference_code', 'id')->all()),
                ...array_slice(self::fields(), 1),
            ])->action(fn (array $data) => ItemRegisterActions::run(fn () => app(ItemRegister::class)->relate(auth()->user(), $parentId, 'leads', 'attach', $data['ids'], $data['revision'], $data)))])
            ->recordActions([
                Action::make('edit')->schema(self::fields())->fillForm(fn (Lead $record): array => ['revision' => $revision(), 'quantity' => $record->pivot->quantity, 'price' => $record->pivot->price])
                    ->action(fn (Lead $record, array $data) => ItemRegisterActions::run(fn () => app(ItemRegister::class)->relate(auth()->user(), $parentId, 'leads', 'update', [$record->id], $data['revision'], $data))),
                Action::make('detach')->requiresConfirmation()->schema([Hidden::make('revision')->default($revision)])
                    ->action(fn (Lead $record, array $data) => ItemRegisterActions::run(fn () => app(ItemRegister::class)->relate(auth()->user(), $parentId, 'leads', 'detach', [$record->id], $data['revision']))),
            ])->toolbarActions([BulkAction::make('detach')->requiresConfirmation()
            ->action(fn (Collection $records) => ItemRegisterActions::run(fn () => app(ItemRegister::class)->relate(auth()->user(), $parentId, 'leads', 'detach', $records->modelKeys(), $revision())))]);
    }
}

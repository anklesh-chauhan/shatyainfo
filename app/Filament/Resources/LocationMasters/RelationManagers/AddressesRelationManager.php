<?php

namespace App\Filament\Resources\LocationMasters\RelationManagers;

use App\Models\Address;
use App\Models\TypeMaster;
use App\Services\Inventory\LocationRegister;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class AddressesRelationManager extends RelationManager
{
    protected static string $relationship = 'addresses';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()->can(['ViewAny:Address', 'View:Address'])
            && app(LocationRegister::class)->visible(auth()->user())->whereKey($ownerRecord->id)->exists();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Hidden::make('location_revision')->default(fn (): string => $this->revision()),
            Select::make('type_master_id')->label('Address Type')->required()->searchable()
                ->options(fn (): array => TypeMaster::query()->where('typeable_type', Address::class)->pluck('name', 'id')->all()),
            TextInput::make('street')->required()->maxLength(255),
            TextInput::make('pin_code')->label('PIN code')->required()->maxLength(9),
            Select::make('area_town_id')->label('Area/Town')->relationship('areaTown', 'area_town')->searchable()->nullable(),
            Toggle::make('is_primary')->label('Primary'),
        ]);
    }

    private function writable(string $permission): bool
    {
        return auth()->user()->can($permission.':Address')
            && app(LocationRegister::class)->canChange(auth()->user(), $this->getOwnerRecord());
    }

    private function revision(): string
    {
        return app(LocationRegister::class)->revision($this->getOwnerRecord()->fresh());
    }

    private function change(?Address $address, ?array $data, string $revision): ?Address
    {
        $owner = $this->getOwnerRecord();
        $record = app(LocationRegister::class)->changeAddress(auth()->user(), $owner->id, $address?->id, $data, $revision);
        $this->dispatch('refresh-location');

        return $record;
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('typeMaster.name')->label('Type'),
            TextColumn::make('street')->searchable(), TextColumn::make('pin_code'),
            TextColumn::make('areaTown.area_town')->label('Area/Town'),
            TextColumn::make('city.name'), TextColumn::make('state.name'), TextColumn::make('country.name'),
        ])->headerActions([
            CreateAction::make()->authorize(fn (): bool => $this->writable('Create'))
                ->using(fn (array $data): Address => $this->change(null, $data, $data['location_revision'])),
        ])->recordActions([
            EditAction::make()->authorize(fn (): bool => $this->writable('Update'))
                ->using(fn (Address $record, array $data): Address => $this->change($record, $data, $data['location_revision'])),
            DeleteAction::make()->authorize(fn (): bool => $this->writable('Delete'))
                ->schema([Hidden::make('location_revision')->default(fn (): string => $this->revision())])
                ->using(fn (Address $record, array $data): bool => $this->change($record, null, $data['location_revision']) === null),
        ])->toolbarActions([]);
    }
}

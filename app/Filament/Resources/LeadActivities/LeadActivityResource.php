<?php

namespace App\Filament\Resources\LeadActivities;

use App\Filament\Actions\ApprovalAction;
use App\Filament\Actions\BulkApprovalAction;
use App\Filament\Resources\BaseResource;
use App\Filament\Resources\LeadActivities\Pages\CreateLeadActivity;
use App\Filament\Resources\LeadActivities\Pages\EditLeadActivity;
use App\Filament\Resources\LeadActivities\Pages\ListLeadActivities;
use App\Models\LeadActivity;
use App\Traits\HasSafeGlobalSearch;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LeadActivityResource extends BaseResource
{
    use HasSafeGlobalSearch;

    protected static ?string $model = LeadActivity::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static ?string $navigationParentItem = 'Lead';

    protected static string|\UnitEnum|null $navigationGroup = 'Sales & Marketing';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('lead_id')
                    ->relationship('lead', 'displayName') // Updated to show Company or Contact Name
                    ->label('Lead')
                    ->searchable()
                    ->required(),

                Select::make('user_id')
                    ->relationship('user', 'name')
                    ->label('User')
                    ->searchable()
                    ->required(),

                TextInput::make('activity_type')
                    ->label('Activity Type')
                    ->required(),

                Textarea::make('description')
                    ->label('Description')
                    ->rows(3)
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('lead.displayName') // Uses Company or Contact Name
                    ->label('Lead')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('user.name')
                    ->label('User')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('activity_type')
                    ->label('Activity Type')
                    ->badge()
                    ->color(fn ($record) => match ($record->activity_type) {
                        'Follow-up Created' => 'success',
                        'Item Attached' => 'warning',
                        default => 'primary',
                    })
                    ->sortable(),

                TextColumn::make('description')
                    ->label('Description')
                    ->tooltip(fn ($record) => $record->description) // Shows full text on hover
                    ->wrap()
                    ->limit(50)
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Created At')
                    ->dateTime('d M Y, h:i A') // Improved date format
                    ->sortable(),
            ])
            ->filters([
                Filter::make('created_at')
                    ->label('Date Range')
                    ->schema([
                        DatePicker::make('from')
                            ->label('From Date'),
                        DatePicker::make('to')
                            ->label('To Date'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'], fn ($query) => $query->whereDate('created_at', '>=', $data['from']))
                        ->when($data['to'], fn ($query) => $query->whereDate('created_at', '<=', $data['to']))
                    ),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                EditAction::make(),
                ApprovalAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([

                    BulkApprovalAction::make(),

                    DeleteBulkAction::make(),
                ]),
            ]);
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
            'index' => ListLeadActivities::route('/'),
            'create' => CreateLeadActivity::route('/create'),
            'edit' => EditLeadActivity::route('/{record}/edit'),
        ];
    }
}

<?php

namespace App\Filament\Resources\SecondarySalesStatements;

use App\Filament\Resources\SecondarySalesStatements\Pages\ManageSecondarySalesStatements;
use App\Models\SecondarySalesStatement;
use App\Services\Field\SecondarySalesService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class SecondarySalesStatementResource extends Resource
{
    protected static ?string $model = SecondarySalesStatement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'SFA Operations';

    public static function canViewAny(): bool
    {
        return (bool) (auth()->user()?->can('Create:SecondarySales') || auth()->user()?->can('Review:SecondarySales'));
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where(fn ($query) => $query->where('created_by', auth()->id())->orWhere('reviewer_id', auth()->id()));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('stockist.name')->searchable(),
                TextColumn::make('period'),
                TextColumn::make('status')->badge(),
                TextColumn::make('created_at')->dateTime(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('source')->label('Download source')
                    ->action(fn (SecondarySalesStatement $record) => Storage::disk('local')->download($record->source_path)),
                Action::make('rows')->label('Inspect rows')->schema([
                    Textarea::make('preview')->label('First 500 rows, normalized values and reconciliation errors')
                        ->default(fn (SecondarySalesStatement $record) => json_encode($record->lines()->limit(500)->get(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))->rows(20)->disabled()->dehydrated(false),
                ])->modalSubmitAction(false),
                Action::make('review')->visible(fn (SecondarySalesStatement $record) => auth()->user()->can('Review:SecondarySales') && (int) $record->reviewer_id === (int) auth()->id() && ! in_array($record->status, ['approved', 'rejected'], true))
                    ->schema([
                        Select::make('decision')->options(['approved' => 'Approve verified statement', 'rejected' => 'Reject for correction'])->required(),
                        CheckboxList::make('physically_verified_line_ids')->label('I physically verified these adjustments')
                            ->options(fn (SecondarySalesStatement $record) => $record->lines()->where('transaction_type', '!=', 'sale')->get()->mapWithKeys(fn ($line) => [$line->id => 'Row '.$line->row_number.' · '.$line->physical_reference.' · ₹'.number_format($line->gross_paise / 100, 2)])),
                        Textarea::make('note')->label('Reconciliation and verification note')->required()->maxLength(2000),
                    ])->action(fn (SecondarySalesStatement $record, array $data) => app(SecondarySalesService::class)
                    ->review(auth()->user(), $record, $data['decision'], $data['note'], array_map('intval', $data['physically_verified_line_ids'] ?? []))),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSecondarySalesStatements::route('/'),
        ];
    }
}

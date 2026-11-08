<?php

namespace App\Filament\Resources\SecondarySalesCorrections;

use App\Filament\Resources\SecondarySalesCorrections\Pages\ManageSecondarySalesCorrections;
use App\Models\SecondarySalesCorrection;
use App\Services\Field\SecondarySalesCorrectionService;
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

class SecondarySalesCorrectionResource extends Resource
{
    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Corrections';

    protected static string|\UnitEnum|null $navigationGroup = 'Secondary Sales';

    protected static ?string $model = SecondarySalesCorrection::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    protected static ?string $modelLabel = 'Secondary sales correction';

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
        return parent::getEloquentQuery()->with(['statement.stockist:id,name', 'replacement:id,status,source_path', 'requester:id,name', 'reviewer:id,name'])
            ->where(fn (Builder $query) => $query->where('requested_by', auth()->id())->orWhere('reviewer_id', auth()->id()));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        $money = fn (?array $summary, string $key): string => $summary === null ? '—' : '₹'.number_format(($summary[$key] ?? 0) / 100, 2);

        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('statement.stockist.name')->label('Stockist'),
                TextColumn::make('statement.period')->label('Period'),
                TextColumn::make('secondary_sales_statement_id')->label('Approved statement'),
                TextColumn::make('kind')->badge(),
                TextColumn::make('status')->badge()->color(fn (string $state): string => match ($state) {
                    'approved' => 'success', 'rejected' => 'danger', default => 'warning',
                }),
                TextColumn::make('original_summary')->label('Original net')->state(fn (SecondarySalesCorrection $record) => $money($record->original_summary, 'net_paise')),
                TextColumn::make('replacement_summary')->label('Replacement net')->state(fn (SecondarySalesCorrection $record) => $money($record->replacement_summary, 'net_paise')),
                TextColumn::make('requester.name')->label('Requested by'),
                TextColumn::make('reviewer.name')->label('Reviewer'),
                TextColumn::make('requested_at')->dateTime()->sortable(),
                TextColumn::make('reviewed_at')->dateTime(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('evidence')->label('Inspect evidence')->schema([
                    Textarea::make('reason')->label('Reason')->default(fn (SecondarySalesCorrection $record) => $record->reason)->disabled()->dehydrated(false),
                    Textarea::make('original')->label('Approved statement totals at request')->default(fn (SecondarySalesCorrection $record) => json_encode($record->original_summary, JSON_PRETTY_PRINT))->rows(9)->disabled()->dehydrated(false),
                    Textarea::make('replacement')->label('Corrected statement totals')->default(fn (SecondarySalesCorrection $record) => json_encode($record->replacement_summary, JSON_PRETTY_PRINT))->rows(9)->visible(fn (SecondarySalesCorrection $record) => $record->kind === 'replacement')->disabled()->dehydrated(false),
                    Textarea::make('released')->label('Released invoice/batch groups and physical adjustments')->default(fn (SecondarySalesCorrection $record) => json_encode(['groups' => $record->released_groups, 'adjustments' => $record->released_adjustments], JSON_PRETTY_PRINT))->rows(6)->visible(fn (SecondarySalesCorrection $record) => $record->status === 'approved')->disabled()->dehydrated(false),
                    Textarea::make('note')->label('Review note')->default(fn (SecondarySalesCorrection $record) => $record->review_note)->disabled()->dehydrated(false),
                ])->modalSubmitAction(false),
                Action::make('replacementRows')->label('Inspect corrected rows')->visible(fn (SecondarySalesCorrection $record) => $record->replacement_statement_id !== null)->schema([
                    Textarea::make('preview')->label('First 500 corrected rows, normalized values and reconciliation errors')
                        ->default(fn (SecondarySalesCorrection $record) => json_encode($record->replacement->lines()->limit(500)->get(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))->rows(20)->disabled()->dehydrated(false),
                ])->modalSubmitAction(false),
                Action::make('replacementSource')->label('Download corrected source')->visible(fn (SecondarySalesCorrection $record) => $record->replacement_statement_id !== null)
                    ->action(fn (SecondarySalesCorrection $record) => Storage::disk('local')->download($record->replacement->source_path)),
                Action::make('review')->label('Review correction')->visible(fn (SecondarySalesCorrection $record) => app(SecondarySalesCorrectionService::class)->canReview(auth()->user(), $record))
                    ->modalDescription(fn (SecondarySalesCorrection $record) => $record->kind === 'replacement'
                        ? 'Approving reverses the approved statement and approves the corrected statement in one step. Its invoice/batch groups and physical adjustments move to the corrected statement; verified figures change accordingly.'
                        : 'Approving reverses the approved statement and removes it from verified figures. Its invoice/batch groups and physical adjustments are released for a later corrected upload.')
                    ->schema([
                        Select::make('decision')->options(fn (SecondarySalesCorrection $record) => ['approved' => $record->kind === 'replacement' ? 'Reverse original and approve corrected statement' : 'Reverse approved statement', 'rejected' => 'Reject correction'])->required(),
                        CheckboxList::make('physically_verified_line_ids')->label('I physically verified these corrected adjustments')
                            ->visible(fn (SecondarySalesCorrection $record) => $record->replacement_statement_id !== null)
                            ->options(fn (SecondarySalesCorrection $record) => $record->replacement->lines()->where('transaction_type', '!=', 'sale')->get()->mapWithKeys(fn ($line) => [$line->id => 'Row '.$line->row_number.' · '.$line->physical_reference.' · ₹'.number_format($line->gross_paise / 100, 2)])),
                        Textarea::make('note')->label('Review reason')->required()->minLength(5)->maxLength(2000),
                    ])->action(fn (SecondarySalesCorrection $record, array $data) => app(SecondarySalesCorrectionService::class)
                    ->review(auth()->user(), $record, $data['decision'], $data['note'], array_map('intval', $data['physically_verified_line_ids'] ?? []))),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSecondarySalesCorrections::route('/'),
        ];
    }
}

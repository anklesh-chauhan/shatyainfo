<?php

namespace App\Filament\Resources\SecondarySalesStatements;

use App\Filament\Resources\SecondarySalesStatements\Pages\ManageSecondarySalesStatements;
use App\Models\SecondarySalesStatement;
use App\Models\User;
use App\Services\Field\SecondarySalesCorrectionService;
use App\Services\Field\SecondarySalesService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

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
                TextColumn::make('source_kind')->label('Template')->badge(),
                TextColumn::make('status')->badge()->color(fn (string $state): string => match ($state) {
                    'approved' => 'success', 'rejected' => 'danger', 'reversed' => 'gray', default => 'warning',
                }),
                TextColumn::make('replaces_statement_id')->label('Replaces')->placeholder('—'),
                TextColumn::make('reversed_at')->dateTime()->placeholder('—'),
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
                Action::make('balances')->label('Inspect stock balances')->visible(fn (SecondarySalesStatement $record) => $record->source_kind === 'stock_balance')->schema([
                    Textarea::make('preview')->label('Opening, movement, calculated closing, variance and rule snapshot')
                        ->default(fn (SecondarySalesStatement $record) => json_encode([
                            'rules' => $record->balance_rules,
                            'rows' => $record->balances()->limit(500)->get(),
                        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))->rows(20)->disabled()->dehydrated(false),
                ])->modalSubmitAction(false),
                Action::make('review')->visible(fn (SecondarySalesStatement $record) => auth()->user()->can('Review:SecondarySales') && (int) $record->reviewer_id === (int) auth()->id() && ! in_array($record->status, ['approved', 'rejected'], true))
                    ->schema([
                        Select::make('decision')->options(['approved' => 'Approve verified statement', 'rejected' => 'Reject for correction'])->required(),
                        CheckboxList::make('physically_verified_line_ids')->label('I physically verified these adjustments')
                            ->options(fn (SecondarySalesStatement $record) => $record->lines()->where('transaction_type', '!=', 'sale')->get()->mapWithKeys(fn ($line) => [$line->id => 'Row '.$line->row_number.' · '.$line->physical_reference.' · ₹'.number_format($line->gross_paise / 100, 2)])),
                        Textarea::make('note')->label('Reconciliation and verification note')->required()->maxLength(2000),
                    ])->action(fn (SecondarySalesStatement $record, array $data) => app(SecondarySalesService::class)
                    ->review(auth()->user(), $record, $data['decision'], $data['note'], array_map('intval', $data['physically_verified_line_ids'] ?? []))),
                Action::make('correction')->label('Request correction')->visible(fn (SecondarySalesStatement $record) => app(SecondarySalesCorrectionService::class)->canRequest(auth()->user(), $record))
                    ->modalDescription('The approved statement is never edited. A reversal removes it from verified figures after review; a replacement reverses it and approves the corrected file in the same step. Both keep the original source, rows and totals as evidence.')
                    ->schema([
                        Select::make('kind')->options(['reversal' => 'Reverse approved statement', 'replacement' => 'Replace with corrected statement'])->required()->live(),
                        Select::make('reviewer_id')->label('RSM / Commercial Excellence reviewer')->options(fn () => User::query()->orderBy('name')->get()->filter(fn ($user) => $user->id !== auth()->id() && $user->can('Review:SecondarySales'))->pluck('name', 'id'))->searchable()->required(),
                        FileUpload::make('file')->label('Corrected statement (same template, stockist and month)')->disk('local')->directory('secondary-import-staging')->visibility('private')->maxSize(5120)
                            ->visible(fn (Get $get) => $get('kind') === 'replacement')->required(fn (Get $get) => $get('kind') === 'replacement')
                            ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                            ->getUploadedFileNameForStorageUsing(fn (TemporaryUploadedFile $file) => Str::uuid().'.'.strtolower($file->getClientOriginalExtension())),
                        Textarea::make('reason')->label('Why the approved statement is wrong and what evidence supports the correction')->required()->minLength(5)->maxLength(2000),
                    ])->action(function (SecondarySalesStatement $record, array $data): void {
                        $file = null;
                        $disk = Storage::disk('local');
                        try {
                            if ($data['kind'] === 'replacement') {
                                abort_unless(is_string($data['file'] ?? null) && str_starts_with($data['file'], 'secondary-import-staging/') && ! str_contains($data['file'], '..'), 422, 'Attach the corrected statement.');
                                $file = new UploadedFile($disk->path($data['file']), basename($data['file']), test: true);
                                Validator::make(['file' => $file], ['file' => ['file', 'extensions:csv,xlsx', 'mimes:csv,txt,xlsx', 'max:5120']])->validate();
                            }
                            app(SecondarySalesCorrectionService::class)->request(auth()->user(), $record, $data, $file);
                            Notification::make()->title('Correction submitted for review')->success()->send();
                        } finally {
                            if (is_string($data['file'] ?? null)) {
                                $disk->delete($data['file']);
                            }
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSecondarySalesStatements::route('/'),
        ];
    }
}

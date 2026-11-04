<?php

namespace App\Filament\Resources\SecondaryOcrSources;

use App\Filament\Resources\SecondaryOcrSources\Pages\ManageSecondaryOcrSources;
use App\Models\SecondaryOcrSource;
use App\Services\Field\SecondaryOcrService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class SecondaryOcrSourceResource extends Resource
{
    protected static ?string $model = SecondaryOcrSource::class;

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
        return $schema
            ->components([
                //
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id'),
                TextColumn::make('period'),
                TextColumn::make('status')->badge(),
                TextColumn::make('mean_confidence')->label('Mean word confidence'),
                TextColumn::make('secondary_sales_statement_id')->label('Mapped statement'),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('source')->label('Download original')->action(fn (SecondaryOcrSource $record) => Storage::disk('local')->download($record->source_path)),
                Action::make('extraction')->label('Inspect extracted text')->schema([
                    Textarea::make('text')->label('OCR text — review against the original before mapping into the CSV template')
                        ->default(fn (SecondaryOcrSource $record) => $record->extracted_text ?? $record->error ?? 'Extraction is awaiting an OCR installation. You may map the original manually.')
                        ->rows(20)->disabled()->dehydrated(false),
                ])->modalSubmitAction(false),
                Action::make('retry')->label('Retry / recover extraction')->visible(fn (SecondaryOcrSource $record) => (int) $record->created_by === (int) auth()->id() && $record->canRetryExtraction())
                    ->action(fn (SecondaryOcrSource $record) => app(SecondaryOcrService::class)->retry(auth()->user(), $record)),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSecondaryOcrSources::route('/'),
        ];
    }
}

<?php

namespace App\Filament\Resources\EDetailingMaterials;

use App\Filament\Resources\EDetailingMaterials\Pages\CreateEDetailingMaterial;
use App\Filament\Resources\EDetailingMaterials\Pages\EditEDetailingMaterial;
use App\Filament\Resources\EDetailingMaterials\Pages\ListEDetailingMaterials;
use App\Models\EDetailingMaterial;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class EDetailingMaterialResource extends Resource
{
    protected static ?string $model = EDetailingMaterial::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartBar;

    protected static string|\UnitEnum|null $navigationGroup = 'Marketing & Field Sales';

    protected static ?string $navigationLabel = 'E-detailing library';

    protected static ?string $modelLabel = 'e-detailing material';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required()->maxLength(255),
            Select::make('item_master_id')->label('Product')->relationship('item', 'item_name')->searchable()->preload(),
            Textarea::make('description')->maxLength(2000)->columnSpanFull(),
            FileUpload::make('file_path')->label('Presentation file')
                ->disk('local')->visibility('private')
                ->directory(fn (): string => EDetailingMaterial::uploadDirectory())
                ->preventFilePathTampering()->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                ->maxSize(10240)->required()->columnSpanFull()
                ->helperText('PDF, JPG, or PNG, up to 10 MB. Use a multipage PDF for a slide deck. Files remain private to your company.'),
            TextInput::make('sort_order')->numeric()->integer()->minValue(0)->maxValue(999999)->default(0)->required(),
            Toggle::make('is_active')->label('Published to field team')->default(false)
                ->helperText('Publish when approved for use. Turn off to withdraw the material from the app.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('sort_order')->columns([
            TextColumn::make('title')->searchable()->sortable(),
            TextColumn::make('item.item_name')->label('Product')->searchable(),
            TextColumn::make('mime_type')->label('Format')->formatStateUsing(fn (string $state): string => $state === 'application/pdf' ? 'PDF' : 'Image')->badge(),
            IconColumn::make('is_active')->label('Published')->boolean(),
            TextColumn::make('presentations_count')->counts('presentations')->label('Presentations'),
            TextColumn::make('updated_at')->dateTime()->sortable(),
        ])->filters([TernaryFilter::make('is_active')->label('Published')])
            ->recordActions([
                Action::make('preview')->icon(Heroicon::OutlinedEye)->url(fn (EDetailingMaterial $record): string => route('e-detailing.preview', $record))->openUrlInNewTab(),
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEDetailingMaterials::route('/'),
            'create' => CreateEDetailingMaterial::route('/create'),
            'edit' => EditEDetailingMaterial::route('/{record}/edit'),
        ];
    }
}

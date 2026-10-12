<?php

namespace App\Filament\Resources\Organizations;

use App\Filament\Actions\ApprovalAction;
use App\Filament\Actions\BulkApprovalAction;
use App\Filament\Clusters\GlobalConfiguration\OrganizationAndCompanyCluster;
use App\Filament\Resources\AccountMasterBankDetailResource\RelationManagers\BankDetailRelationManager;
use App\Filament\Resources\AccountMasters\RelationManagers\AddressesRelationManager;
use App\Filament\Resources\BaseResource;
use App\Filament\Resources\Organizations\Pages\CreateOrganization;
use App\Filament\Resources\Organizations\Pages\EditOrganization;
use App\Filament\Resources\Organizations\Pages\ListOrganizations;
use App\Models\Organization;
use App\Traits\HasSafeGlobalSearch;
use DateTimeZone;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
// Import Fieldset
use Filament\Tables\Table;

 // Import ViewAction

class OrganizationResource extends BaseResource
{
    use HasSafeGlobalSearch;

    protected static ?string $model = Organization::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2; // More relevant icon

    protected static ?string $cluster = OrganizationAndCompanyCluster::class;

    protected static ?string $navigationLabel = 'Organization Profile';

    protected static ?int $navigationSort = 1000;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('Organization Details')
                    ->tabs([
                        Tab::make('General Information')
                            ->icon('heroicon-o-information-circle')
                            ->schema([
                                TextInput::make('name')
                                    ->required()
                                    ->maxLength(255)
                                    ->autofocus() // Focus on this field when the form loads
                                    ->placeholder('e.g., Acme Corporation'),
                                TextInput::make('display_name')
                                    ->maxLength(255)
                                    ->helperText('Optional: A user-friendly name for display purposes.')
                                    ->placeholder('e.g., Acme Co.'),
                                FileUpload::make('logo')
                                    ->image()
                                    ->directory('organization-logos')
                                    ->maxSize(2048) // 2MB max
                                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/gif', 'image/webp']) // Add webp
                                    ->disk('public')
                                    ->preserveFilenames()
                                    ->imagePreviewHeight('150') // Adjust preview size
                                    ->columnSpanFull(), // Make logo span full width for better display
                                Textarea::make('description')
                                    ->rows(3) // Make textarea a bit taller
                                    ->columnSpanFull()
                                    ->placeholder('A brief description of the organization.'),
                                Select::make('status')
                                    ->options([
                                        'active' => 'Active',
                                        'inactive' => 'Inactive',
                                        'pending' => 'Pending',
                                    ])
                                    ->required()
                                    ->default('active')
                                    ->native(false), // Use a custom select UI
                                Select::make('industry_type_id')
                                    ->relationship('industryType', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->native(false) // Use a custom select UI
                                    ->placeholder('Select an industry'),
                                Select::make('parent_organization_id')
                                    ->relationship('parentOrganization', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->native(false) // Use a custom select UI
                                    ->placeholder('Select a parent organization'),
                                DatePicker::make('founded_at')
                                    ->label('Founded On'),
                                TextInput::make('size')
                                    ->maxLength(255)
                                    ->placeholder('e.g., 50-200 employees'),
                                TextInput::make('annual_revenue')
                                    ->numeric()
                                    ->prefix('INR')
                                    ->minValue(0),
                                // ->mask(RawJs::make('$money($event.target.value)')), // Use RawJs for currency masking
                                TextInput::make('operation_hours')
                                    ->maxLength(255)
                                    ->placeholder('e.g., Mon-Fri, 9 AM - 5 PM'),
                                Select::make('timezone')
                                    ->options(DateTimeZone::listIdentifiers())
                                    ->searchable()
                                    ->preload()
                                    ->native(false) // Use a custom select UI
                                    ->placeholder('Select timezone'),
                                TextInput::make('language')
                                    ->maxLength(255)
                                    ->placeholder('e.g., English'),
                            ]),

                        Tab::make('Contact Information')
                            ->icon('heroicon-o-phone')
                            ->schema([
                                TextInput::make('website')
                                    ->url()
                                    ->maxLength(255)
                                    ->suffixIcon('heroicon-o-link') // Add a link icon
                                    ->placeholder('e.g., https://www.example.com'),
                                TextInput::make('email')
                                    ->email()
                                    ->maxLength(255)
                                    ->suffixIcon('heroicon-o-envelope')
                                    ->placeholder('e.g., info@example.com'),
                                TextInput::make('phone')
                                    ->tel()
                                    ->maxLength(255)
                                    ->suffixIcon('heroicon-o-phone')
                                    ->placeholder('e.g., +1234567890'),
                                TextInput::make('fax')
                                    ->maxLength(255)
                                    ->suffixIcon('heroicon-o-printer')
                                    ->placeholder('e.g., +1234567891'),
                                Fieldset::make('Contact Person Details')
                                    ->schema([
                                        TextInput::make('contact_person')
                                            ->maxLength(255)
                                            ->placeholder('e.g., John Doe'),
                                        TextInput::make('contact_person_email')
                                            ->email()
                                            ->maxLength(255)
                                            ->placeholder('e.g., john.doe@example.com'),
                                        TextInput::make('contact_person_phone')
                                            ->tel()
                                            ->maxLength(255)
                                            ->placeholder('e.g., +1987654321'),
                                    ])->columns(1), // Adjust columns for fieldset
                            ]),

                        Tab::make('Legal & Registration')
                            ->icon('heroicon-o-building-library')
                            ->schema([
                                TextInput::make('legal_name')
                                    ->maxLength(255)
                                    ->placeholder('e.g., Acme Corporation Ltd.'),
                                TextInput::make('registration_number')
                                    ->maxLength(255)
                                    ->placeholder('e.g., ABC-12345'),
                                TextInput::make('gst_number') // Consider renaming to 'GST_number' for consistency or use label()
                                    ->maxLength(255)
                                    ->placeholder('e.g., 22AAAAA0000A1Z2'),
                                DatePicker::make('registration_date'),
                                TextInput::make('legal_status')
                                    ->maxLength(255)
                                    ->placeholder('e.g., Public Company, Private Limited'),
                            ]),

                        Tab::make('Social Media')
                            ->icon('heroicon-o-hashtag')
                            ->schema([
                                TextInput::make('linkedin_url')
                                    ->url()
                                    ->maxLength(255)
                                    ->suffixIcon('heroicon-o-link')
                                    ->placeholder('e.g., https://linkedin.com/company/acme'),
                                TextInput::make('twitter_url')
                                    ->url()
                                    ->maxLength(255)
                                    ->suffixIcon('heroicon-o-link')
                                    ->placeholder('e.g., https://twitter.com/acmecorp'),
                                TextInput::make('facebook_url')
                                    ->url()
                                    ->maxLength(255)
                                    ->suffixIcon('heroicon-o-link')
                                    ->placeholder('e.g., https://facebook.com/acmecorp'),
                                TextInput::make('instagram_url')
                                    ->url()
                                    ->maxLength(255)
                                    ->suffixIcon('heroicon-o-link')
                                    ->placeholder('e.g., https://instagram.com/acmecorp'),
                            ]),

                        Tab::make('Metadata & Audit')
                            ->icon('heroicon-o-server')
                            ->schema([
                                KeyValue::make('metadata')
                                    ->keyLabel('Key') // More generic label
                                    ->valueLabel('Value')
                                    ->columnSpanFull()
                                    ->helperText('Additional key-value pairs for organizational data.'),
                                Select::make('created_by')
                                    ->relationship('creator', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->disabled() // Typically, this field is not user-editable
                                    ->placeholder('Select creator'),
                                Select::make('updated_by')
                                    ->relationship('updater', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->disabled() // Typically, this field is not user-editable
                                    ->placeholder('Select updater'),
                            ]),
                    ])->columnSpanFull(), // Make tabs span full width
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('logo')
                    ->disk('public')
                    ->circular() // Make logo circular for a nicer look
                    ->toggleable(isToggledHiddenByDefault: true), // Allow users to hide/show this column
                TextColumn::make('name')
                    ->searchable()
                    ->sortable() // Enable sorting
                    ->toggleable()
                    ->copyable() // Allow copying the name
                    ->label('Organization Name'), // Custom label
                TextColumn::make('display_name')
                    ->searchable()
                    ->sortable()
                    ->toggleable()
                    ->label('Display Name'),
                TextColumn::make('email')
                    ->searchable()
                    ->sortable()
                    ->toggleable()
                    ->icon('heroicon-o-envelope') // Add an icon
                    ->copyable(),
                TextColumn::make('phone')
                    ->searchable()
                    ->sortable()
                    ->toggleable()
                    ->icon('heroicon-o-phone') // Add an icon
                    ->copyable(),
                TextColumn::make('website')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->url(fn ($record) => $record->website) // Make it a clickable URL
                    ->openUrlInNewTab() // Open in new tab
                    ->icon('heroicon-o-link'),
                TextColumn::make('industryType.name') // Display related industry name
                    ->label('Industry')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('status')
                    ->badge() // Display status as a badge
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'inactive' => 'danger',
                        'pending' => 'warning',
                        default => 'gray',
                    })
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true) // Hidden by default
                    ->label('Created At'),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true) // Hidden by default
                    ->label('Last Updated'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'active' => 'Active',
                        'inactive' => 'Inactive',
                        'pending' => 'Pending',
                    ])
                    ->native(false) // Use a custom select UI
                    ->placeholder('Filter by Status'),
                SelectFilter::make('industry_type_id')
                    ->relationship('industryType', 'name')
                    ->native(false) // Use a custom select UI
                    ->placeholder('Filter by Industry'),
                TrashedFilter::make(), // Add soft delete filter
            ])
            ->recordActions([
                ViewAction::make(), // Add a view action
                EditAction::make(),
                ApprovalAction::make(),
                DeleteAction::make(), // Add direct delete action
            ])
            ->toolbarActions([
                BulkActionGroup::make([

                    BulkApprovalAction::make(),

                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(), // For soft deletes
                    RestoreBulkAction::make(), // For soft deletes
                ]),
            ])
            ->defaultSort('name', 'asc'); // Default sort by name ascending
    }

    public static function getRelations(): array
    {
        return [
            AddressesRelationManager::class,
            BankDetailRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrganizations::route('/'),
            'create' => CreateOrganization::route('/create'),
            'edit' => EditOrganization::route('/{record}/edit'),
        ];
    }
}

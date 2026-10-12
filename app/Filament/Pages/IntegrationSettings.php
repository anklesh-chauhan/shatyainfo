<?php

namespace App\Filament\Pages;

use App\Enums\IntegrationProvider;
use App\Filament\Clusters\GlobalConfiguration\OperationalConfigCluster;
use App\Services\IntegrationSettingsService;
use App\Services\Travel\GoogleRoutesService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class IntegrationSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $cluster = OperationalConfigCluster::class;

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Integrations';

    protected static ?string $title = 'API Integrations';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected string $view = 'filament.pages.integration-settings';

    public ?array $data = [];

    public function mount(): void
    {
        $service = app(IntegrationSettingsService::class);

        $state = [];

        foreach (IntegrationProvider::cases() as $provider) {
            $state[$provider->value] = $service->formStateForProvider($provider);
        }

        $this->form->fill($state);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Tabs::make('Integrations')
                    ->tabs(
                        collect(IntegrationProvider::cases())
                            ->map(fn (IntegrationProvider $provider): Tab => $this->buildProviderTab($provider))
                            ->all()
                    )
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $service = app(IntegrationSettingsService::class);

        foreach (IntegrationProvider::cases() as $provider) {
            $service->saveProvider(
                $provider,
                $state[$provider->value] ?? []
            );
        }

        $this->mount();

        Notification::make()
            ->title('Integration settings saved')
            ->success()
            ->send();
    }

    protected function buildProviderTab(IntegrationProvider $provider): Tab
    {
        $config = $provider->config();
        $providerKey = $provider->value;
        $fields = [
            Toggle::make("{$providerKey}.is_enabled")
                ->label('Enable integration')
                ->helperText('When disabled, stored credentials are ignored and environment fallbacks are used where configured.'),
        ];

        foreach ($config['fields'] ?? [] as $fieldKey => $fieldConfig) {

            $fields[] = TextEntry::make("{$providerKey}.has_{$fieldKey}")
                ->label("Stored {$fieldConfig['label']}")
                ->state(
                    data_get($this->data, "{$providerKey}.has_{$fieldKey}")
                        ? 'A value is saved. Enter a new value below to replace it.'
                        : 'Not configured yet. Enter a value below or use the matching .env variable.'
                );

            $fields[] = $this->makeFieldComponent(
                $providerKey,
                $fieldKey,
                $fieldConfig
            );
        }

        $section = Section::make($provider->label())
            ->description($config['description'] ?? null)
            ->schema($fields)
            ->columns(1);

        if ($provider->isTestable()) {
            $section->footerActions([
                Action::make("test_{$providerKey}")
                    ->label('Test Connection')
                    ->icon(Heroicon::OutlinedSignal)
                    ->action(fn () => $this->testProvider($provider)),
            ]);
        }

        return Tab::make($provider->label())
            ->schema([$section]);
    }

    /**
     * @param  array<string, mixed>  $fieldConfig
     */
    protected function makeFieldComponent(string $providerKey, string $fieldKey, array $fieldConfig): TextInput|Select
    {
        $name = "{$providerKey}.{$fieldKey}";
        $type = $fieldConfig['type'] ?? 'text';

        if ($type === 'select') {
            return Select::make($name)
                ->label($fieldConfig['label'])
                ->options($fieldConfig['options'] ?? [])
                ->placeholder($fieldConfig['placeholder'] ?? null)
                ->helperText($fieldConfig['helper'] ?? null);
        }

        $input = TextInput::make($name)
            ->label($fieldConfig['label'])
            ->placeholder($fieldConfig['placeholder'] ?? null)
            ->helperText($fieldConfig['helper'] ?? null);

        if ($type === 'password') {
            $input->password()->revealable();
        }

        if ($type === 'password') {
            $input->dehydrated(fn (?string $state): bool => filled($state));
        }

        return $input;
    }

    protected function testProvider(IntegrationProvider $provider): void
    {
        $service = app(IntegrationSettingsService::class);

        if ($provider === IntegrationProvider::GoogleMaps && ! app(GoogleRoutesService::class)->isConfigured()) {
            Notification::make()
                ->title('No API key configured')
                ->body('Save an API key before testing the connection.')
                ->warning()
                ->send();

            return;
        }

        $service->notifyTestResult($provider, $service->testProvider($provider));
    }
}

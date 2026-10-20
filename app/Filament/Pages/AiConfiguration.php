<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\GlobalConfiguration\OperationalConfigCluster;
use App\Services\SalespersonAi\AiProviderClient;
use App\Services\SalespersonAi\AiSettingsService;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Throwable;

class AiConfiguration extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $cluster = OperationalConfigCluster::class;

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'AI Configuration';

    protected static ?string $title = 'AI Configuration';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected string $view = 'filament.pages.ai-configuration';

    public ?array $data = [];

    #[Locked]
    public string $companyScope = '';

    #[Locked]
    public array $savedKeys = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'administration_admin']) ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->companyScope = app(AiSettingsService::class)->scope();
        $this->fillSettings();
    }

    private function fillSettings(): void
    {
        $state = app(AiSettingsService::class)->formState();
        $this->savedKeys = [
            'openai' => $state['has_openai_key'],
            'anthropic' => $state['has_anthropic_key'],
        ];
        unset($state['has_openai_key'], $state['has_anthropic_key']);
        $this->form->fill($state);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Sales assistant')
                ->description('Choose the provider used for this company’s pre-call briefings. Changes take effect after saving.')
                ->schema([
                    Toggle::make('enabled')->label('Enable AI briefings')
                        ->helperText('When off, the standard briefing remains available and no AI requests are sent.'),
                    Select::make('provider')->label('Active provider')
                        ->options(['openai' => 'OpenAI', 'anthropic' => 'Claude (Anthropic)', 'ollama' => 'Local model (Ollama)'])
                        ->required()->native(false),
                ])->columns(2),
            Tabs::make('Provider settings')->tabs([
                $this->cloudProviderTab('openai', 'OpenAI'),
                $this->cloudProviderTab('anthropic', 'Claude'),
                Tab::make('Local model (Ollama)')->schema([
                    TextInput::make('ollama_base_url')->label('Ollama server address')
                        ->placeholder('http://127.0.0.1:11434')->maxLength(255)
                        ->helperText('Use localhost when Ollama runs on the ERP server, or a private IPv4 address for another server.'),
                    TextInput::make('ollama_model')->label('Installed model name')
                        ->placeholder('Enter the model name shown in Ollama')->maxLength(150)
                        ->helperText('The model must already be installed on that server. No cloud API key is required.'),
                ]),
            ])->columnSpanFull(),
            Section::make('Usage limits')->schema([
                TextInput::make('daily_requests')->label('Company requests per day')
                    ->integer()->minValue(1)->maxValue(100000)->required(),
                TextInput::make('user_requests_per_minute')->label('Requests per rep per minute')
                    ->integer()->minValue(1)->maxValue(100)->required(),
                TextInput::make('timeout_seconds')->label('Response timeout (seconds)')
                    ->integer()->minValue(1)->maxValue(120)->required()
                    ->helperText('Local models may need a longer timeout.'),
                TextInput::make('max_output_tokens')->label('Maximum response tokens')
                    ->integer()->minValue(128)->maxValue(2048)->required(),
            ])->columns(2),
        ])->statePath('data');
    }

    private function cloudProviderTab(string $provider, string $label): Tab
    {
        return Tab::make($label)->schema([
            TextInput::make($provider.'_model')->label('Model name')->maxLength(150)
                ->helperText('Enter a model available to your API account.'),
            TextInput::make($provider.'_api_key')->label('API key')->password()->maxLength(1024)
                ->autocomplete('off')
                ->helperText(($this->savedKeys[$provider] ?? false)
                    ? 'A key is configured. Leave blank to keep it, or enter a replacement.'
                    : 'Enter your API key. It will be stored encrypted.'),
            Toggle::make('clear_'.$provider.'_key')->label('Remove saved key')
                ->helperText('Disable AI or choose another provider before removing its only key.'),
        ]);
    }

    private function authorizeSettings(): void
    {
        abort_unless(static::canAccess(), 403);
        abort_unless($this->companyScope === app(AiSettingsService::class)->scope(), 403);
    }

    public function save(): void
    {
        $this->authorizeSettings();
        app(AiSettingsService::class)->save($this->form->getState());
        $this->fillSettings();
        Notification::make()->title('AI configuration saved')->success()->send();
    }

    public function testConnection(): void
    {
        $this->authorizeSettings();
        $config = app(AiSettingsService::class)->prepare($this->form->getState(), forTest: true);
        $key = 'ai-settings-test:'.$this->companyScope.':'.auth()->id();
        if (RateLimiter::tooManyAttempts($key, 3)) {
            Notification::make()->title('Please wait before testing again')->warning()->send();

            return;
        }
        RateLimiter::hit($key, 60);
        try {
            app(AiProviderClient::class)->selectFacts($config['provider'], [
                'connection_test' => 'This is a connection test using sample text.',
            ], $config);
            Notification::make()->title('Connection successful')
                ->body('The selected model returned a valid response. Save changes to use these settings.')
                ->success()->send();
        } catch (Throwable) {
            Notification::make()->title('Connection could not be verified')
                ->body('Check the model name, credentials, server address, and timeout, then try again.')
                ->danger()->send();
        }
    }
}

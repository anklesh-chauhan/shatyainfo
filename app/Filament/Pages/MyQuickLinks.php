<?php

namespace App\Filament\Pages;

use App\Support\TopbarLinks;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class MyQuickLinks extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $title = 'My quick links';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.my-quick-links';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Filament::auth()->check();
    }

    public function mount(): void
    {
        $this->form->fill(['links' => array_map(fn (string $module): array => ['module' => $module], app(TopbarLinks::class)->selected(Filament::auth()->user()))]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->schema([
            Repeater::make('links')->label('Top bar links')
                ->helperText('Choose up to 8 modules and drag to reorder them. Your choices are saved for your account in this company. Only modules you can access are shown.')
                ->schema([
                    Select::make('module')->label('Module')->options(fn (): array => array_map(fn (array $link): string => $link['label'], app(TopbarLinks::class)->catalog()))
                        ->required()->searchable()->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                ])->maxItems(8)->defaultItems(0)->reorderableWithButtons()->addActionLabel('Add quick link'),
        ])->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();
        Validator::make($state, [
            'links' => ['present', 'array', 'max:8'],
            'links.*.module' => ['required', 'string', 'distinct', Rule::in(array_keys(app(TopbarLinks::class)->catalog()))],
        ])->validate();
        Filament::auth()->user()->forceFill(['topbar_links' => array_values(array_column($state['links'], 'module'))])->save();
        Notification::make()->title('Quick links saved')->success()->send();
        $this->redirect(static::getUrl());
    }

    public function resetDefaults(): void
    {
        Filament::auth()->user()->forceFill(['topbar_links' => null])->save();
        Notification::make()->title('Pharma quick links restored')->success()->send();
        $this->redirect(static::getUrl());
    }
}

<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\GlobalConfiguration\OperationalConfigCluster;
use App\Services\DateFormatService;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

class DateSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $cluster = OperationalConfigCluster::class;

    protected static ?string $navigationLabel = 'Date Format';

    protected static ?string $title = 'Date Format';

    protected static ?int $navigationSort = 7;

    protected string $view = 'filament.pages.date-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'administration_admin']) ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->form->fill(['date_format' => app(DateFormatService::class)->dateFormat()]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('date_format')->label('Date display format')
                ->options(DateFormatService::FORMATS)
                ->required()
                ->rules([Rule::in(array_keys(DateFormatService::FORMATS))])
                ->helperText('Applies throughout this company’s panels, reports and field app. The app receives changes when it signs in or refreshes its session; offline devices use their last saved setting.'),
        ])->statePath('data');
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        app(DateFormatService::class)->save($this->form->getState()['date_format']);
        Notification::make()->title('Date format saved')->success()->send();
    }
}

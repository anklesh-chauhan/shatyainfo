<?php

namespace App\Providers;

use App\Database\Macros\BlueprintMacros;
use App\Events\ApprovalStatusChanged;
use App\Listeners\ApprovalListener;
use App\Models\Media;
use App\Models\Visit;
use App\Observers\MediaObserver;
use App\Observers\VisitObserver;
use App\Services\DateFormatService;
use Carbon\Carbon;
use Filament\Actions\Exports\ExportColumn;
use Filament\Forms\Components\DateTimePicker;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Spatie\Multitenancy\Commands\TenantsArtisanCommand;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(DateFormatService::class);
        $this->commands([
            TenantsArtisanCommand::class,
        ]);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ExportColumn::configureUsing(function (ExportColumn $column): void {
            $name = $column->getName();
            if (preg_match('/(^|[._])date$|_on$/', $name)) {
                $column->formatStateUsing(fn ($state): ?string => blank($state) ? null : Carbon::parse($state)->format(app(DateFormatService::class)->dateFormat()));
            } elseif (preg_match('/_at$|^(first_punch_in|last_punch_out)$/', $name)) {
                $column->formatStateUsing(fn ($state): ?string => blank($state) ? null : Carbon::parse($state)->format(app(DateFormatService::class)->dateTimeFormat()));
            }
        });
        Table::configureUsing(fn (Table $table) => $table
            ->defaultDateDisplayFormat(fn (): string => app(DateFormatService::class)->dateFormat())
            ->defaultDateTimeDisplayFormat(fn (): string => app(DateFormatService::class)->dateTimeFormat()));
        Schema::configureUsing(fn (Schema $schema) => $schema
            ->defaultDateDisplayFormat(fn (): string => app(DateFormatService::class)->dateFormat())
            ->defaultDateTimeDisplayFormat(fn (): string => app(DateFormatService::class)->dateTimeFormat()));
        DateTimePicker::configureUsing(fn (DateTimePicker $picker) => $picker
            ->native(false)
            ->defaultDateDisplayFormat(fn (): string => app(DateFormatService::class)->dateFormat())
            ->defaultDateTimeDisplayFormat(fn (): string => app(DateFormatService::class)->dateFormat().' H:i')
            ->defaultDateTimeWithSecondsDisplayFormat(fn (): string => app(DateFormatService::class)->dateTimeFormat()));

        $this->loadMigrationsFrom(database_path('migrations/tenant'));

        BlueprintMacros::register();
        Event::listen(ApprovalStatusChanged::class, ApprovalListener::class);
        Media::observe(MediaObserver::class);
        Visit::observe(VisitObserver::class);
    }
}

<?php

namespace App\Providers;

use App\Database\Macros\BlueprintMacros;
use App\Events\ApprovalStatusChanged;
use App\Listeners\ApprovalListener;
use App\Models\ApprovalSetting;
use App\Models\Media;
use App\Models\Visit;
use App\Observers\MediaObserver;
use App\Observers\VisitObserver;
use App\Services\DateFormatService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\Exports\ExportColumn;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Field;
use Filament\Infolists\Components\Entry;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Column;
use Filament\Tables\Filters\BaseFilter;
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
        $isApprovalField = fn (string $name): bool => array_intersect(explode('.', $name), [
            'approval_status', 'approved_by', 'approved_at', 'approvedBy', 'approved_remarks', 'rejected_by', 'rejected_at', 'rejected_remarks',
        ]) !== [];
        Column::configureUsing(function (Column $column) use ($isApprovalField): void {
            if ($isApprovalField($column->getName())) {
                $column->hidden(fn (): bool => ! ApprovalSetting::showsApprovalField($column->getTable()->getModel(), $column->getName()));
            }
        });
        BaseFilter::configureUsing(function (BaseFilter $filter) use ($isApprovalField): void {
            if ($isApprovalField($filter->getName())) {
                $filter->hidden(fn (): bool => ! ApprovalSetting::showsApprovalField($filter->getTable()->getModel(), $filter->getName()));
            }
        });
        foreach ([Entry::class, Field::class] as $componentClass) {
            $componentClass::configureUsing(function ($component) use ($isApprovalField): void {
                if ($isApprovalField($component->getName())) {
                    $component->hidden(fn (): bool => ! ApprovalSetting::showsApprovalField($component->getModel(), $component->getName()));
                }
            });
        }
        Action::configureUsing(function (Action $action): void {
            if (in_array($action->getName(), ['send_for_approval', 'send_for_bulk_approval'], true)) {
                $action->hidden(fn (): bool => ! ApprovalSetting::showsApprovalField($action->getTable()?->getModel() ?? $action->getRecord(), 'approval_status'));
            }
        });
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

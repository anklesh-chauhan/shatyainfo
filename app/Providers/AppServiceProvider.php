<?php

namespace App\Providers;

use App\Database\Macros\BlueprintMacros;
use App\Events\ApprovalStatusChanged;
use App\Listeners\ApprovalListener;
use App\Models\Media;
use App\Models\Visit;
use App\Observers\MediaObserver;
use App\Observers\VisitObserver;
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
        $this->commands([
            TenantsArtisanCommand::class,
        ]);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(database_path('migrations/tenant'));

        BlueprintMacros::register();
        Event::listen(ApprovalStatusChanged::class, ApprovalListener::class);
        Media::observe(MediaObserver::class);
        Visit::observe(VisitObserver::class);
    }
}

<?php

use App\Console\Commands\ScanModels;
use App\Support\Tenancy\RunsAcrossTenants;
use Illuminate\Foundation\Console\ClosureCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    app(RunsAcrossTenants::class)->each(function () {
        Artisan::call('visits:auto-checkout');
    });
})->everyMinute()->name('visits-auto-checkout')->withoutOverlapping();

Artisan::command('inspire', function () {
    /** @var ClosureCommand $this */
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('scan:models', function () {
    Artisan::call(ScanModels::class);
})->describe('Scan the app/Models directory and list all models.');

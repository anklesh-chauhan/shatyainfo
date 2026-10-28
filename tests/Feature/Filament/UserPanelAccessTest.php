<?php

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('allows an active employee linked by login id to access the web panel', function () {
    $user = reportingUser('Linked Employee');
    $employee = $user->employee;
    $user->forceFill(['employee_id' => null])->save();

    expect($user->fresh()->canAccessPanel(Filament::getPanel('admin')))->toBeTrue();

    $employee->update(['is_active' => false]);
    expect($user->fresh()->canAccessPanel(Filament::getPanel('admin')))->toBeFalse();
});

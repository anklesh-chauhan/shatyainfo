<?php

use App\Filament\Resources\RcpaCompetitors\RcpaCompetitorResource;
use App\Filament\Resources\RcpaObservations\RcpaObservationResource;
use App\Filament\Resources\SecondarySalesStatements\SecondarySalesStatementResource;
use App\Filament\Resources\SkuUnitConversions\SkuUnitConversionResource;
use App\Filament\Resources\TerritorySalesTargets\TerritorySalesTargetResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

it('shows shield-aligned modules for ViewAny permissions without custom manage flags', function () {
    $user = reportingUser('ShieldNavAdmin');
    foreach ([
        'ViewAny:SkuUnitConversion',
        'ViewAny:RcpaCompetitor',
        'ViewAny:RcpaObservation',
        'ViewAny:SecondarySalesStatement',
        'ViewAny:TerritorySalesTarget',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $user->givePermissionTo([
        'ViewAny:SkuUnitConversion',
        'ViewAny:RcpaCompetitor',
        'ViewAny:RcpaObservation',
        'ViewAny:SecondarySalesStatement',
        'ViewAny:TerritorySalesTarget',
    ]);
    $this->actingAs($user);

    expect(SkuUnitConversionResource::canViewAny())->toBeTrue()
        ->and(RcpaCompetitorResource::canViewAny())->toBeTrue()
        ->and(RcpaObservationResource::canViewAny())->toBeTrue()
        ->and(SecondarySalesStatementResource::canViewAny())->toBeTrue()
        ->and(TerritorySalesTargetResource::canViewAny())->toBeTrue();
});

it('still shows modules for legacy custom permissions', function () {
    $user = reportingUser('LegacyNavRep');
    foreach ([
        'Manage:SkuUnitConversion',
        'Manage:RcpaCompetitor',
        'Capture:Rcpa',
        'Create:SecondarySales',
        'View:SalesTargets',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }
    $user->givePermissionTo([
        'Manage:SkuUnitConversion',
        'Manage:RcpaCompetitor',
        'Capture:Rcpa',
        'Create:SecondarySales',
        'View:SalesTargets',
    ]);
    $this->actingAs($user);

    expect(SkuUnitConversionResource::canViewAny())->toBeTrue()
        ->and(RcpaCompetitorResource::canViewAny())->toBeTrue()
        ->and(RcpaObservationResource::canViewAny())->toBeTrue()
        ->and(SecondarySalesStatementResource::canViewAny())->toBeTrue()
        ->and(TerritorySalesTargetResource::canViewAny())->toBeTrue();
});

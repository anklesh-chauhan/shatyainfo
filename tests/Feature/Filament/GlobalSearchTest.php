<?php

use App\Filament\Resources\ApprovalFlows\ApprovalFlowResource;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Models\ApprovalFlow;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

it('binds ctrl+k to the sidebar search field', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $html = view('filament-panels::livewire.global-search', [
        'results' => null,
    ])->render();

    expect($html)
        ->toContain('x-mousetrap.global.command-k.ctrl-k')
        ->toContain('Ctrl+K')
        ->toContain('$store.sidebar?.open()');
});

it('searches every admin resource without querying missing columns', function () {
    $this->actingAs(reportingUser('Meera'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $queries = [];

    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $failures = [];

    foreach (Filament::getPanel('admin')->getResources() as $resource) {
        try {
            $resource::getGlobalSearchResults('meera');
        } catch (Throwable $exception) {
            $failures[] = class_basename($resource).': '.$exception->getMessage();
        }
    }

    expect($failures)->toBeEmpty();
    expect(implode("\n", $queries))->not->toMatch('/`[A-Z]/');
});

it('searches approval flows by module and employees by name', function () {
    $user = reportingUser('Meera');
    $user->givePermissionTo(Permission::findOrCreate('ViewAny:Employee', 'web'));
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    ApprovalFlow::query()->create(['module' => 'LeaveApplication']);

    $approvalSql = [];
    $employeeSql = [];

    DB::listen(function ($query) use (&$approvalSql, &$employeeSql): void {
        if (str_contains($query->sql, 'approval_flows')) {
            $approvalSql[] = $query->sql;
        }

        if (str_contains($query->sql, 'employees')) {
            $employeeSql[] = $query->sql;
        }
    });

    ApprovalFlowResource::getGlobalSearchResults('LeaveApplication');
    $results = EmployeeResource::getGlobalSearchResults('meera');

    expect(implode("\n", $approvalSql))->toContain('module');
    expect(implode("\n", $employeeSql))->toContain('first_name');
    expect($results->pluck('title')->implode(' '))->toContain('Meera');
});

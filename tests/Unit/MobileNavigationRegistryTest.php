<?php

use App\Services\Mobile\MobileNavigationRegistry;
use App\Services\OptionalModuleService;

it('orders the nine mobile groups and keeps settings on the web', function () {
    $registry = new MobileNavigationRegistry;

    expect($registry->groups())->toHaveCount(9)
        ->and(array_column($registry->groups(), 'key'))->toBe([
            'workspace', 'field', 'customers', 'sales', 'secondary', 'marketing', 'inventory', 'hr', 'reports',
        ])
        ->and(array_column($registry->groups(), 'label'))->not->toContain('Settings & Administration')
        ->and($registry->webGroups()[9]['label'])->toBe('Settings & Administration')
        ->and($registry->groups()[0]['collapsed'])->toBeFalse()
        ->and($registry->webGroups()[9]['collapsed'])->toBeTrue();
});

it('orders schema 1 screens from the admin navigation metadata', function () {
    $registry = new MobileNavigationRegistry;
    $entries = $registry->entries();

    expect(array_column($entries, 'key'))->toBe([
        'g01-02', 'g01-03', 'g01-06',
        'g02-01', 'g02-02', 'g02-03', 'g02-04', 'g02-06', 'g02-07',
        'g03-01',
        'g04-01', 'g04-04', 'g04-09',
        'g06-03', 'g06-04', 'g06-07',
    ])
        ->and($entries[0]['label'])->toBe('Punch In/Out')
        ->and($entries[0]['screen_key'])->toBe('todays-tour')
        ->and(array_column($entries, 'label', 'key')['g04-01'])->toBe('Leads')
        ->and(array_column($entries, 'screen_key', 'key')['g04-01'])->toBe('leads')
        ->and(array_column($entries, 'label', 'key')['g04-04'])->toBe('Deals')
        ->and(array_column($entries, 'screen_key', 'key')['g04-04'])->toBe('deals')
        ->and(array_column($entries, 'label', 'key')['g04-09'])->toBe('Territory Sales Targets')
        ->and(array_column($entries, 'permission_key', 'key')['g02-06'])->toBe('SalesDcrExpense')
        ->and(OptionalModuleService::RESOURCES['expenses'])->toContain('SalesDcrExpense');
});

it('keeps every mobile group in the ordered catalog', function () {
    $groups = (new MobileNavigationRegistry)->orderedGroups();

    $byKey = array_column($groups, null, 'key');

    expect($groups)->toHaveCount(9)
        ->and($byKey['inventory']['items'])->toBe([])
        ->and($byKey['reports']['items'])->toBe([])
        ->and($byKey['workspace']['items'][1]['screen_key'])->toBe('todays-tour');
});

it('chooses today tour only when that screen is present', function () {
    $registry = new MobileNavigationRegistry;

    expect($registry->homeFor(['todays-tour', 'accounts']))->toBe('todays-tour')
        ->and($registry->homeFor(['accounts']))->toBe('modules');
});

it('matches the current admin navigation', function () {
    $registry = new MobileNavigationRegistry;

    expect($registry->drift())->toBe([])
        ->and(file_get_contents(dirname(__DIR__, 2).'/app/Providers/Filament/AdminPanelProvider.php'))->toContain('webGroups()')
        ->and(file_get_contents(dirname(__DIR__, 2).'/routes/api.php'))->toContain("Route::get('mobile-navigation', MobileNavigationController::class)");
});

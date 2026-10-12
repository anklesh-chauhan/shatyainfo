<?php

namespace App\Providers\Filament;

class MenuProvider
{
    public static function getMenuItems()
    {
        return [
            ['label' => 'Leads', 'route' => 'filament.admin.resources.leads.index'],
            ['label' => 'Contacts', 'route' => 'filament.admin.resources.contacts.index'],
            ['label' => 'Accounts', 'route' => 'filament.admin.resources.account-masters.index'],
            ['label' => 'Items', 'route' => 'filament.admin.resources.items.index'],
            ['label' => 'Sales DCR', 'route' => 'filament.admin.resources.sales-dcrs.index'],
            ['label' => 'Visit Routes', 'route' => 'filament.admin.resources.visit-routes.index'],
            ['label' => 'Tour Plans', 'route' => 'filament.admin.resources.tour-plans.index'],
            ['label' => 'Expense Config', 'route' => 'filament.admin.resources.expense-config.index'],
        ];
    }
}

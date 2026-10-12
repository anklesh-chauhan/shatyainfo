<?php

namespace Database\Seeders;

use App\Models\CityClass;
use App\Models\ExpenseConfiguration;
use App\Models\ExpenseConfigurationCondition;
use App\Models\ExpenseConfigurationSlab;
use App\Models\ExpenseType;
use App\Models\TravelType;
use Illuminate\Database\Seeder;

class ExpensePolicySeeder extends Seeder
{
    public function run(): void
    {
        /*
        |--------------------------------------------------------------------------
        | 1. MASTER DATA
        |--------------------------------------------------------------------------
        */

        $hq = TravelType::firstOrCreate(['code' => 'hq', 'name' => 'HQ']);
        $ex = TravelType::firstOrCreate(['code' => 'ex_hq', 'name' => 'EX_STATION']);
        $out = TravelType::firstOrCreate(['code' => 'outstation', 'name' => 'OUTSTATION']);
        $conference = TravelType::firstOrCreate(['code' => 'conference', 'name' => 'CONFERENCE']);

        $classA = CityClass::firstOrCreate(['code' => 'a', 'name' => 'A']);
        $classB = CityClass::firstOrCreate(['code' => 'b', 'name' => 'B']);
        $classC = CityClass::firstOrCreate(['code' => 'c', 'name' => 'C']);

        $da = ExpenseType::firstOrCreate(['code' => 'DAILY_ALLOWANCE'], ['name' => 'Daily Allowance']);
        $travel = ExpenseType::firstOrCreate(['code' => 'TRAVEL'], ['name' => 'Travel']);
        $conf = ExpenseType::firstOrCreate(['code' => 'CLIENT_MEETING'], ['name' => 'Client Meeting']);

        /*
        |--------------------------------------------------------------------------
        | 2. DAILY ALLOWANCE RULES
        |--------------------------------------------------------------------------
        */

        $this->createFlatRule($da->id, 'hq', 300, 100);
        $this->createFlatRule($da->id, 'ex_hq', 330, 100);
        $this->createFlatRule($da->id, 'outstation', 650, 100);

        /*
        |--------------------------------------------------------------------------
        | 3. CONFERENCE RULE
        |--------------------------------------------------------------------------
        */

        $confRule = ExpenseConfiguration::create([
            'name' => 'Conference Meeting Allowance',
            'expense_type_id' => $conf->id,
            'calculation_strategy' => 'flat',
            'rate' => 1000,
            'priority' => 200,
            'effective_from' => now(),
        ]);

        ExpenseConfigurationCondition::create([
            'expense_configuration_id' => $confRule->id,
            'condition_key' => 'travel_type',
            'operator' => '=',
            'value' => 'conference',
        ]);

        /*
        |--------------------------------------------------------------------------
        | 4. HILL STATION MULTIPLIER
        |--------------------------------------------------------------------------
        */

        $hill = ExpenseConfiguration::create([
            'name' => 'Hill Station Daily Allowance Multiplier',
            'expense_type_id' => $da->id,
            'calculation_strategy' => 'multiplier',
            'rate' => 1.5,
            'priority' => 50,
            'effective_from' => now(),
        ]);

        ExpenseConfigurationCondition::create([
            'expense_configuration_id' => $hill->id,
            'condition_key' => 'is_hill_station',
            'operator' => '=',
            'value' => 'true',
        ]);

        /*
        |--------------------------------------------------------------------------
        | 5. TRAVEL SLAB RULE
        |--------------------------------------------------------------------------
        */

        $travelRule = ExpenseConfiguration::create([
            'name' => 'Travel Distance Slab Allowance',
            'expense_type_id' => $travel->id,
            'calculation_strategy' => 'slab',
            'priority' => 100,
            'effective_from' => now(),
        ]);

        // Conditions
        $this->addCondition($travelRule, 'distance', '>=', 20);
        $this->addCondition($travelRule, 'travel_type', '!=', 'hq');

        // Slabs
        $this->addSlab($travelRule, 1, 200, 3.5);
        $this->addSlab($travelRule, 201, 300, 2.5);
        $this->addSlab($travelRule, 301, 500, 2.0);
        $this->addSlab($travelRule, 501, null, 1.5);
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS
    |--------------------------------------------------------------------------
    */

    protected function createFlatRule($expenseTypeId, $travelType, $amount, $priority)
    {
        $config = ExpenseConfiguration::create([
            'name' => match ($travelType) {
                'hq' => 'HQ Daily Allowance',
                'ex_hq' => 'Ex-Station Daily Allowance',
                'outstation' => 'Outstation Daily Allowance',
                default => ucwords(str_replace('_', ' ', $travelType)).' Daily Allowance',
            },
            'expense_type_id' => $expenseTypeId,
            'calculation_strategy' => 'flat',
            'rate' => $amount,
            'priority' => $priority,
            'effective_from' => now(),
        ]);

        $this->addCondition($config, 'travel_type', '=', $travelType);
    }

    protected function addCondition($config, $key, $operator, $value)
    {
        ExpenseConfigurationCondition::create([
            'expense_configuration_id' => $config->id,
            'condition_key' => $key,
            'operator' => $operator,
            'value' => (string) $value,
        ]);
    }

    protected function addSlab($config, $min, $max, $rate)
    {
        ExpenseConfigurationSlab::create([
            'expense_configuration_id' => $config->id,
            'min_value' => $min,
            'max_value' => $max,
            'rate' => $rate,
        ]);
    }
}

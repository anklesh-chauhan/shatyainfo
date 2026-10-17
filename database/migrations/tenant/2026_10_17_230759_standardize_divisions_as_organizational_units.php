<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $connection = Schema::getConnection();
        $connection->transaction(function () use ($connection): void {
            $units = $connection->table('organizational_units')->get()->keyBy('id');
            $divisionTypes = $connection->table('type_masters')->where('typeable_type', 'App\\Models\\OrganizationalUnit')
                ->where('name', 'Division')->pluck('id')->all();
            $resolve = function (?int $id) use ($units, $divisionTypes): ?int {
                $seen = [];
                while ($id !== null && isset($units[$id])) {
                    if (isset($seen[$id])) {
                        throw new RuntimeException('Resolve the organizational unit cycle before migrating divisions.');
                    }
                    $seen[$id] = true;
                    $unit = $units[$id];
                    if (in_array($unit->type_master_id, $divisionTypes)) {
                        return $id;
                    }
                    $id = $unit->parent_id;
                }

                return null;
            };
            foreach (['positions', 'employment_details'] as $table) {
                foreach ($connection->table($table)->get(['id', 'organizational_unit_id', 'division_ou_id']) as $record) {
                    $division = $resolve($record->organizational_unit_id ?? $record->division_ou_id);
                    $connection->table($table)->where('id', $record->id)->update(['division_ou_id' => $division]);
                }
            }
        });
        if (Schema::hasColumn('employment_details', 'division_id')) {
            Schema::table('employment_details', fn (Blueprint $table) => $table->dropColumn('division_id'));
        }
        if (Schema::hasTable('emp_divisions')) {
            Schema::drop('emp_divisions');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('employment_details', 'division_id')) {
            Schema::table('employment_details', fn (Blueprint $table) => $table->unsignedBigInteger('division_id')->nullable());
        }
    }
};

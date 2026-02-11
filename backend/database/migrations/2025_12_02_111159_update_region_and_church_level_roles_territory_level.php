<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Updates region-level and church-level roles with their territory_level
     */
    public function up(): void
    {
        // Ensure column exists (in case diocese migration wasn't run)
        if (!Schema::hasColumn('roles', 'territory_level')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->enum('territory_level', ['diocese', 'region', 'subregion', 'church'])
                      ->nullable()
                      ->after('guard_name')
                      ->comment('Territory level for this role');
            });
        }

        // Update region-level roles
        $regionRoles = DB::table('roles')->whereIn('name', [
            'Regional Overseer',
            'Regional Secretary',
            'Regional Treasurer',
            'Regional Committee Member',
        ])->update(['territory_level' => 'region']);

        // Update church-level roles
        $churchRoles = DB::table('roles')->whereIn('name', [
            'Senior Pastor',
            'Associate Pastor',
            'Church Elder',
            'Church Secretary',
            'Church Treasurer',
            'Deacon',
            'Deaconess',
            'Church Member',
        ])->update(['territory_level' => 'church']);

        // Update subregion-level roles (for completeness)
        $subregionRoles = DB::table('roles')->whereIn('name', [
            'Subregional Overseer',
            'Subregional Secretary',
            'Subregional Treasurer',
        ])->update(['territory_level' => 'subregion']);

        // Count what was updated
        $regionCount = DB::table('roles')
            ->where('territory_level', 'region')
            ->count();

        $churchCount = DB::table('roles')
            ->where('territory_level', 'church')
            ->count();

        $subregionCount = DB::table('roles')
            ->where('territory_level', 'subregion')
            ->count();

        echo "\n✅ Updated {$regionCount} region-level roles\n";
        echo "✅ Updated {$subregionCount} subregion-level roles\n";
        echo "✅ Updated {$churchCount} church-level roles\n";
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reset region-level roles
        DB::table('roles')->whereIn('name', [
            'Regional Overseer',
            'Regional Secretary',
            'Regional Treasurer',
            'Regional Committee Member',
        ])->update(['territory_level' => null]);

        // Reset church-level roles
        DB::table('roles')->whereIn('name', [
            'Senior Pastor',
            'Associate Pastor',
            'Church Elder',
            'Church Secretary',
            'Church Treasurer',
            'Deacon',
            'Deaconess',
            'Church Member',
        ])->update(['territory_level' => null]);

        // Reset subregion-level roles
        DB::table('roles')->whereIn('name', [
            'Subregional Overseer',
            'Subregional Secretary',
            'Subregional Treasurer',
        ])->update(['territory_level' => null]);
    }
};

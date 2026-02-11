<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Updates ONLY diocese-level roles with territory_level = 'diocese'
     */
    public function up(): void
    {
        // Check if column exists, if not add it
        if (!Schema::hasColumn('roles', 'territory_level')) {
            Schema::table('roles', function (Blueprint $table) {
                $table->enum('territory_level', ['diocese', 'region', 'subregion', 'church'])
                      ->nullable()
                      ->after('guard_name')
                      ->comment('Territory level for this role');
            });
        }

        // Update ONLY diocese-level roles
        DB::table('roles')->whereIn('name', [
            'Bishop',
            'Diocese Secretary',
            'Diocese Treasurer',
            'Diocese Council Member',
        ])->update(['territory_level' => 'diocese']);

        // Log what was updated
        $updatedCount = DB::table('roles')
            ->whereIn('name', ['Bishop', 'Diocese Secretary', 'Diocese Treasurer', 'Diocese Council Member'])
            ->where('territory_level', 'diocese')
            ->count();

        echo "\n✅ Updated {$updatedCount} diocese-level roles\n";
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Reset only diocese-level roles back to NULL
        DB::table('roles')->whereIn('name', [
            'Bishop',
            'Diocese Secretary',
            'Diocese Treasurer',
            'Diocese Council Member',
        ])->update(['territory_level' => null]);
    }
};

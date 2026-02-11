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
        Schema::table('modules', function (Blueprint $table) {
            if (!Schema::hasColumn('modules', 'module_group_id')) {
                $table->foreignId('module_group_id')
                      ->nullable()
                      ->after('id')
                      ->constrained('module_groups')
                      ->onDelete('set null');
            } else {
                // Column exists, just add the foreign key constraint if not present
                $table->foreign('module_group_id')
                      ->references('id')
                      ->on('module_groups')
                      ->onDelete('set null');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('modules', function (Blueprint $table) {
            $table->dropForeign(['module_group_id']);
            $table->dropColumn('module_group_id');
        });
    }
};

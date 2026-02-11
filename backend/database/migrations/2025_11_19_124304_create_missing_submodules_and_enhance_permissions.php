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
        // Create the missing submodules table
        if (!Schema::hasTable('submodules')) {
            Schema::create('submodules', function (Blueprint $table) {
                $table->id();
                $table->foreignId('module_id')->constrained()->onDelete('cascade');
                $table->string('title');
                $table->string('path');
                $table->boolean('is_active')->default(true);
                $table->text('description')->nullable();
                $table->timestamps();

                $table->unique(['module_id', 'title']);
                $table->index(['module_id', 'is_active']);
            });
        }

        // Now add the missing columns to existing tables

        // Add territorial fields to permissions table
        Schema::table('permissions', function (Blueprint $table) {
            if (!Schema::hasColumn('permissions', 'module_id')) {
                $table->foreignId('module_id')->nullable()->after('guard_name')->constrained()->onDelete('cascade');
            }
            if (!Schema::hasColumn('permissions', 'submodule_id')) {
                $table->foreignId('submodule_id')->nullable()->after('module_id')->constrained()->onDelete('cascade');
            }
            if (!Schema::hasColumn('permissions', 'sub_submodule_id')) {
                $table->foreignId('sub_submodule_id')->nullable()->after('submodule_id')->constrained('sub_submodules')->onDelete('cascade');
            }
            if (!Schema::hasColumn('permissions', 'action')) {
                $table->string('action', 50)->nullable()->after('sub_submodule_id');
            }
            if (!Schema::hasColumn('permissions', 'territory_scope')) {
                $table->enum('territory_scope', ['diocese', 'region', 'subregion', 'church'])->nullable()->after('action');
            }
        });

        // Add territorial fields to roles table
        Schema::table('roles', function (Blueprint $table) {
            if (!Schema::hasColumn('roles', 'territory_level')) {
                $table->enum('territory_level', ['diocese', 'region', 'subregion', 'church'])->nullable()->after('guard_name');
            }
            if (!Schema::hasColumn('roles', 'description')) {
                $table->text('description')->nullable()->after('territory_level');
            }
            if (!Schema::hasColumn('roles', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('description');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop added columns from permissions
        Schema::table('permissions', function (Blueprint $table) {
            $columns = ['sub_submodule_id', 'submodule_id', 'module_id', 'action', 'territory_scope'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('permissions', $column)) {
                    if (in_array($column, ['sub_submodule_id', 'submodule_id', 'module_id'])) {
                        $table->dropForeign(['permissions_' . $column . '_foreign']);
                    }
                    $table->dropColumn($column);
                }
            }
        });

        // Drop added columns from roles
        Schema::table('roles', function (Blueprint $table) {
            $columns = ['territory_level', 'description', 'is_active'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('roles', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        // Drop submodules table
        Schema::dropIfExists('submodules');
    }
};

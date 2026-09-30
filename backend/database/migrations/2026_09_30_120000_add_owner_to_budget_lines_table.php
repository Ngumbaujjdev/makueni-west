<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A budget line can belong to one church (a line only that church uses),
 * alongside the diocese's shared lines (no owner). A church's own lines have
 * slugs unique within that church, so the global unique slug index becomes
 * unique per owner.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('budget_lines', function (Blueprint $table) {
            $table->string('territory_type')->nullable()->after('territory_scope');
            $table->unsignedBigInteger('territory_id')->nullable()->after('territory_type');
            $table->index(['territory_type', 'territory_id']);
            $table->dropUnique(['slug']);
            $table->unique(['slug', 'territory_type', 'territory_id']);
        });
    }

    public function down(): void
    {
        Schema::table('budget_lines', function (Blueprint $table) {
            $table->dropUnique(['slug', 'territory_type', 'territory_id']);
            $table->unique('slug');
            $table->dropIndex(['territory_type', 'territory_id']);
            $table->dropColumn(['territory_type', 'territory_id']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Approval rules at every level (docs/specs/accounting-spec.md, A4b): a rule can
 * belong to a church or region - for itself, or (a region) for its churches
 * too. No owner = the diocese's standard rule for a level, as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_workflows', function (Blueprint $table) {
            $table->foreignId('territory_id')->nullable()->after('level')->constrained('territories')->cascadeOnDelete();
            $table->enum('reach', ['self', 'below'])->default('self')->after('territory_id');
            $table->index(['territory_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('approval_workflows', function (Blueprint $table) {
            $table->dropIndex(['territory_id', 'is_active']);
            $table->dropConstrainedForeignId('territory_id');
            $table->dropColumn('reach');
        });
    }
};

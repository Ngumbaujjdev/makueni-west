<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sunday School Teachers have no other data source (unlike Pastors/
     * Associate Pastors, which live in user_territory_assignments and are
     * surfaced read-only via DemographicsController::clergySummary()) - a
     * manual counter, same shape as the other *_count columns on this table.
     */
    public function up(): void
    {
        Schema::table('church_demographics', function (Blueprint $table) {
            $table->integer('sunday_school_teachers_count')->nullable()->after('sunday_school_female_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('church_demographics', function (Blueprint $table) {
            $table->dropColumn('sunday_school_teachers_count');
        });
    }
};

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
        Schema::create('church_attendance_records', function (Blueprint $table) {
            $table->id();

            // Territory (polymorphic, same enum as budgets/church_demographics -
            // entry is only ever done at church level, enforced at the
            // controller layer)
            $table->enum('territory_type', ['diocese', 'region', 'subregion', 'church']);
            $table->unsignedBigInteger('territory_id');
            $table->index(['territory_type', 'territory_id']);

            // The real calendar date this record is for - Sundays don't align
            // to fiscal_months boundaries, so this is the source of truth.
            // fiscal_year_id/fiscal_month_id are derived from it at write time
            // and denormalized here purely for fast rollup joins.
            $table->date('service_date');
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years')->onDelete('restrict');
            $table->foreignId('fiscal_month_id')->constrained('fiscal_months')->onDelete('restrict');

            $table->enum('service_type', ['sunday_service', 'special_event', 'ministry_gathering']);
            $table->string('event_name')->nullable(); // e.g. "Youth Kesha" - only meaningful for special_event/ministry_gathering

            // Attendance counts - children broken down by gender per the
            // module plan; adults/youth stay single numbers to keep weekly
            // entry fast
            $table->unsignedInteger('adults_count')->default(0);
            $table->unsignedInteger('youth_count')->default(0);
            $table->unsignedInteger('children_male_count')->default(0);
            $table->unsignedInteger('children_female_count')->default(0);

            $table->text('notes')->nullable();

            // User tracking
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('updated_by')->nullable()->constrained('users')->onDelete('set null');

            $table->timestamps();
            $table->softDeletes();

            $table->index('service_date');
            $table->index('service_type');
            $table->index(['fiscal_year_id', 'fiscal_month_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('church_attendance_records');
    }
};

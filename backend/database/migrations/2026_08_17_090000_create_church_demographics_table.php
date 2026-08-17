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
        Schema::create('church_demographics', function (Blueprint $table) {
            $table->id();

            // Territory (polymorphic, same enum as budgets - entry is only ever
            // done at church level per the module spec, enforced at the
            // controller layer, not the DB layer, for consistency with Budget)
            $table->enum('territory_type', ['diocese', 'region', 'subregion', 'church']);
            $table->unsignedBigInteger('territory_id');
            $table->index(['territory_type', 'territory_id']);

            // Period - reuses the existing fiscal_years/fiscal_months lookup
            // tables rather than a new period system
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years')->onDelete('restrict');
            $table->foreignId('fiscal_month_id')->constrained('fiscal_months')->onDelete('restrict');

            // Membership counts
            $table->unsignedInteger('total_members')->default(0);
            $table->unsignedInteger('male_count')->default(0);
            $table->unsignedInteger('female_count')->default(0);
            $table->unsignedInteger('youth_count')->default(0);
            $table->unsignedInteger('womens_fellowship_count')->default(0);
            $table->unsignedInteger('mens_fellowship_count')->default(0);
            $table->unsignedInteger('sunday_school_male_count')->default(0);
            $table->unsignedInteger('sunday_school_female_count')->default(0);
            $table->unsignedInteger('seniors_count')->default(0);

            // This month's changes
            $table->unsignedInteger('new_members_count')->default(0);
            $table->unsignedInteger('transferred_out_count')->default(0);

            // Spiritual activities
            $table->unsignedInteger('baptisms_count')->default(0);
            $table->unsignedInteger('communion_participants_count')->default(0);
            $table->unsignedInteger('conversions_count')->default(0);

            // Workflow
            $table->enum('status', ['draft', 'submitted', 'approved', 'flagged', 'changes_requested'])
                  ->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();

            // User tracking
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('updated_by')->nullable()->constrained('users')->onDelete('set null');

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('church_demographics');
    }
};

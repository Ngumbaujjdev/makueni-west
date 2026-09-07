<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Demographics submissions no longer have to be monthly - a church can
     * record half-yearly or yearly instead (see Church::getDemographicsMode()).
     * fiscal_semi_annual_id carries the half-yearly case, reusing the
     * already-populated fiscal_semi_annuals table the Budget module built;
     * the yearly case needs both fiscal_month_id and fiscal_semi_annual_id
     * null, so fiscal_month_id has to stop being required.
     */
    public function up(): void
    {
        Schema::table('church_demographics', function (Blueprint $table) {
            $table->foreignId('fiscal_semi_annual_id')->nullable()->after('fiscal_month_id')
                ->constrained('fiscal_semi_annuals')->onDelete('set null');
        });

        Schema::table('church_demographics', function (Blueprint $table) {
            $table->foreignId('fiscal_month_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('church_demographics', function (Blueprint $table) {
            $table->dropForeign(['fiscal_semi_annual_id']);
            $table->dropColumn('fiscal_semi_annual_id');
        });

        Schema::table('church_demographics', function (Blueprint $table) {
            $table->foreignId('fiscal_month_id')->nullable(false)->change();
        });
    }
};

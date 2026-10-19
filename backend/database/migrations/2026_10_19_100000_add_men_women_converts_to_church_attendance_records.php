<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The phone app's Sunday service form counts men and women separately, and the
 * converts (docs/specs/mobile-app-spec.md, M6; agreed 2026-10-19). Adults stays
 * the total the web and every report read; when men and women are given it is
 * their sum. Old records keep these empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('church_attendance_records', function (Blueprint $table) {
            $table->unsignedInteger('men_count')->nullable()->after('adults_count');
            $table->unsignedInteger('women_count')->nullable()->after('men_count');
            $table->unsignedInteger('converts_count')->nullable()->after('children_female_count');
        });
    }

    public function down(): void
    {
        Schema::table('church_attendance_records', function (Blueprint $table) {
            $table->dropColumn(['men_count', 'women_count', 'converts_count']);
        });
    }
};

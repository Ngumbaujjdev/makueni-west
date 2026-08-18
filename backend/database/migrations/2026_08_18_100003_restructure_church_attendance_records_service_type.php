<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('church_attendance_records', function (Blueprint $table) {
            $table->foreignId('gathering_category_id')->nullable()->after('service_type')
                ->constrained('gathering_categories')->restrictOnDelete();
            $table->foreignId('gathering_type_id')->nullable()->after('gathering_category_id')
                ->constrained('gathering_types')->nullOnDelete();
        });

        // Backfill: the old service_type enum value maps 1:1 to a
        // gathering_categories.slug seeded in the previous migration.
        $categoryIds = DB::table('gathering_categories')->pluck('id', 'slug');

        foreach ($categoryIds as $slug => $categoryId) {
            DB::table('church_attendance_records')
                ->where('service_type', $slug)
                ->update(['gathering_category_id' => $categoryId]);
        }

        Schema::table('church_attendance_records', function (Blueprint $table) {
            $table->dropIndex(['service_type']);
            $table->dropColumn('service_type');
            $table->unsignedBigInteger('gathering_category_id')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('church_attendance_records', function (Blueprint $table) {
            $table->enum('service_type', ['sunday_service', 'special_event', 'ministry_gathering'])->nullable()->after('fiscal_month_id');
        });

        $slugsById = DB::table('gathering_categories')->pluck('slug', 'id');

        foreach ($slugsById as $categoryId => $slug) {
            DB::table('church_attendance_records')
                ->where('gathering_category_id', $categoryId)
                ->update(['service_type' => $slug]);
        }

        Schema::table('church_attendance_records', function (Blueprint $table) {
            $table->index('service_type');
            $table->dropForeign(['gathering_category_id']);
            $table->dropForeign(['gathering_type_id']);
            $table->dropColumn(['gathering_category_id', 'gathering_type_id']);
        });
    }
};

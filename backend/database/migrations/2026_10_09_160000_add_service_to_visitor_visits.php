<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A visit can be at a weekly service (Settings > Service times - "Sunday
 * morning"), not only at one of the church's gathering types. Stored the
 * way attendance stores Sunday: the sunday_service category, and the
 * service's name (docs/specs/people-and-care-spec.md, round 2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitor_visits', function (Blueprint $table) {
            $table->foreignId('gathering_category_id')->nullable()->after('gathering_type_id')->constrained('gathering_categories')->nullOnDelete();
            $table->string('service_name', 80)->nullable()->after('gathering_category_id');
        });
    }

    public function down(): void
    {
        Schema::table('visitor_visits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('gathering_category_id');
            $table->dropColumn('service_name');
        });
    }
};

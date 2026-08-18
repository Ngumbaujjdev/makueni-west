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
        Schema::create('gathering_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Sunday Service, Ministry Gathering, Special Event
            $table->string('slug')->unique();
            $table->string('icon')->nullable(); // remix icon class, e.g. ri-calendar-event-line
            // Marks the one category that behaves like the recurring weekly
            // calendar (Sunday Service today) - services.php and the
            // Sunday-highlight UX key off this instead of a hardcoded
            // string comparison against the old 'sunday_service' enum value.
            $table->boolean('is_weekly')->default(false);
            $table->unsignedInteger('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
        });

        // These 3 categories replace the old service_type enum values 1:1 -
        // inserted here (not in a seeder) so the very next migration can
        // backfill church_attendance_records against them regardless of
        // whether `migrate --seed` or a bare `migrate` was run.
        $now = now();
        DB::table('gathering_categories')->insert([
            ['name' => 'Sunday Service', 'slug' => 'sunday_service', 'icon' => 'ri-calendar-check-line', 'is_weekly' => true, 'display_order' => 1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Ministry Gathering', 'slug' => 'ministry_gathering', 'icon' => 'ri-group-line', 'is_weekly' => false, 'display_order' => 2, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Special Event', 'slug' => 'special_event', 'icon' => 'ri-star-line', 'is_weekly' => false, 'display_order' => 3, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gathering_categories');
    }
};

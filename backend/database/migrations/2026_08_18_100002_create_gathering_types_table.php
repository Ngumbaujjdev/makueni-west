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
        Schema::create('gathering_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gathering_category_id')->constrained('gathering_categories')->cascadeOnDelete();

            // Church-owned, not diocese-wide - each church defines its own
            // list of specific gatherings (e.g. "Kesha", "Tuesday
            // Fellowship") under the shared global categories. Attendance
            // entry is only ever done at church level, same as
            // church_attendance_records.territory_id, so no territory_type
            // column is needed here.
            $table->unsignedBigInteger('territory_id');

            $table->string('name');
            $table->string('slug');
            $table->string('icon')->nullable(); // falls back to the category's icon when null
            $table->unsignedInteger('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();

            $table->unique(['territory_id', 'slug']);
            $table->index(['territory_id', 'gathering_category_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gathering_types');
    }
};

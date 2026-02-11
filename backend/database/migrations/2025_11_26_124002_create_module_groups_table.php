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
        Schema::create('module_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // e.g., "Financial Management", "Church Management"
            $table->string('slug')->unique(); // e.g., "financial-management"
            $table->string('icon')->nullable(); // Group icon (e.g., "ri-money-dollar-line")
            $table->integer('order')->default(0); // Display order in sidebar
            $table->string('territory_scope'); // diocese, region, subregion, church
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('module_groups');
    }
};

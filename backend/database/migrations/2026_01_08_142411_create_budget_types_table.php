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
        Schema::create('budget_types', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Monthly, Quarterly, Yearly, Custom
            $table->string('slug')->unique(); // monthly, quarterly, yearly, custom
            $table->integer('duration_months')->nullable(); // 1, 3, 12, null (for custom)
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('budget_types');
    }
};

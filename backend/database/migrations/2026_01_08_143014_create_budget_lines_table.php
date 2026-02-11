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
        Schema::create('budget_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_category_id')->constrained('budget_categories')->onDelete('cascade');
            $table->string('name'); // Equipment, Retreats, Salaries, Utilities, etc.
            $table->string('slug')->unique(); // equipment, retreats, salaries, etc.
            $table->enum('territory_scope', ['diocese', 'region', 'subregion', 'church', 'all'])->default('all');
            $table->text('description')->nullable();
            $table->boolean('is_system_default')->default(false); // true for seeded, false for user-created
            $table->boolean('is_active')->default(true);
            $table->integer('display_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('updated_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('budget_lines');
    }
};

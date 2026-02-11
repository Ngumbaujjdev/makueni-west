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
        Schema::create('fiscal_semi_annuals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years')->onDelete('cascade');
            $table->integer('number'); // 1-2 (H1, H2)
            $table->string('name'); // H1 2026, H2 2026
            $table->date('start_date');
            $table->date('end_date');
            $table->timestamps();
            
            $table->unique(['fiscal_year_id', 'number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fiscal_semi_annuals');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A place's own version of a position, grade or allowance set above it
 * (docs/specs/hr-spec.md): its pay package for a position, its range for a
 * grade, its amount for an allowance. The nearest place's version wins.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_place_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('territory_id')->constrained('territories')->cascadeOnDelete();
            $table->enum('kind', ['position', 'grade', 'allowance']);
            $table->unsignedBigInteger('item_id');
            $table->json('settings');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['territory_id', 'kind', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_place_settings');
    }
};

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
        // Create submodules first if it doesn't exist (needed for FK)
        if (!Schema::hasTable('submodules')) {
            Schema::create('submodules', function (Blueprint $table) {
                $table->id();
                $table->foreignId('module_id')->constrained()->onDelete('cascade');
                $table->string('title');
                $table->string('path');
                $table->boolean('is_active')->default(true);
                $table->text('description')->nullable();
                $table->timestamps();

                $table->unique(['module_id', 'title']);
                $table->index(['module_id', 'is_active']);
            });
        }

        // Create sub_submodules table
        if (!Schema::hasTable('sub_submodules')) {
            Schema::create('sub_submodules', function (Blueprint $table) {
                $table->id();
                $table->foreignId('submodule_id')->constrained()->onDelete('cascade');
                $table->string('title');
                $table->string('path');
                $table->boolean('is_active')->default(true);
                $table->text('description')->nullable();
                $table->timestamps();

                $table->unique(['submodule_id', 'title']);
                $table->index(['submodule_id', 'is_active']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sub_submodules');
        Schema::dropIfExists('submodules');
    }
};

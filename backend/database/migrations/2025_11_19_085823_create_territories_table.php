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
        Schema::create('territories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->index();
            $table->string('code', 20)->unique()->index(); // e.g., AICK-MWD-KLB-001
            $table->string('territory_type'); // Using enum: global, diocese, region, subregion, church
            $table->foreignId('parent_territory_id')->nullable()->constrained('territories')->onDelete('restrict');
            $table->tinyInteger('level')->index(); // Calculated depth: 0=Global, 1=Diocese, etc.
            $table->string('full_path')->nullable()->index(); // Cached path for quick queries
            $table->boolean('is_active')->default(true)->index();

            // Address and contact information
            $table->text('address')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->string('town')->nullable();
            $table->string('county')->nullable();

            // GPS coordinates for mapping
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();

            // Operational details
            $table->date('established_date')->nullable();
            $table->text('description')->nullable();
            $table->json('metadata')->nullable(); // For custom attributes

            // Audit fields
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('updated_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
            $table->softDeletes();

            // Indexes for performance
            $table->index(['territory_type', 'is_active']);
            $table->index(['parent_territory_id', 'is_active']);
            $table->index(['level', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('territories');
    }
};

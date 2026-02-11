<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('super_admin_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->onDelete('cascade');
            $table->foreignId('primary_territory_id')->constrained('territories')->onDelete('restrict');
            $table->boolean('global_access')->default(true);
            $table->string('default_territory_type')->default('diocese'); // Default view level
            $table->json('preferences')->nullable(); // UI preferences, default filters, etc.

            // Access restrictions (even for super admin if needed)
            $table->json('restricted_territories')->nullable(); // Array of territory IDs to restrict
            $table->json('restricted_modules')->nullable(); // Array of module IDs to restrict

            $table->timestamps();

            $table->index(['user_id', 'global_access']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('super_admin_configs');
    }
};

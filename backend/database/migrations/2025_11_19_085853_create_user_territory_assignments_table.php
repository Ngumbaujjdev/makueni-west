<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_territory_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('territory_id')->constrained()->onDelete('cascade');
            $table->foreignId('role_id')->constrained()->onDelete('cascade');
            $table->string('assignment_type')->default('primary'); // primary, secondary, temporary, acting

            // Permission flags
            $table->boolean('can_see_children')->default(true); // See all sub-territories
            $table->boolean('can_see_siblings')->default(false); // See peer territories
            $table->boolean('can_manage_users')->default(false); // Manage users in territory
            $table->boolean('can_manage_finances')->default(false); // Manage financial data

            // Assignment details
            $table->text('assignment_reason')->nullable();
            $table->date('effective_from')->default(now());
            $table->date('expires_at')->nullable();
            $table->boolean('is_active')->default(true)->index();

            // Audit trail
            $table->foreignId('assigned_by')->constrained('users')->onDelete('restrict');
            $table->timestamp('assigned_at')->default(now());
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Constraints
            $table->unique(['user_id', 'territory_id', 'role_id'], 'user_territory_role_unique');
            $table->index(['user_id', 'is_active']);
            $table->index(['territory_id', 'is_active']);
            $table->index(['assignment_type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_territory_assignments');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('impersonation_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('impersonator_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('impersonated_user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('territory_context_id')->nullable()->constrained('territories')->onDelete('set null');

            $table->text('reason'); // Required for audit
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamp('started_at')->default(now());
            $table->timestamp('expires_at');
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('ended_by')->nullable()->constrained('users')->onDelete('set null');
            $table->text('end_reason')->nullable();
            $table->boolean('is_active')->default(true)->index();

            $table->timestamps();

            $table->index(['impersonator_id', 'is_active']);
            $table->index(['impersonated_user_id', 'is_active']);
            $table->index(['started_at', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impersonation_sessions');
    }
};

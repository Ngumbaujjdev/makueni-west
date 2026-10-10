<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "I paid by Pay Bill - here is my M-Pesa code" (docs/specs/accounting-spec.md,
 * A10f): a giver's or treasurer's claim, checked with Safaricom (Transaction
 * Status) before anything is recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_claims', function (Blueprint $table) {
            $table->id();
            $table->string('trans_id', 20)->index();
            $table->foreignId('territory_id')->constrained('territories')->cascadeOnDelete();
            $table->string('purpose', 4);
            $table->string('giver_name', 150)->nullable();
            $table->string('giver_phone', 20)->nullable();
            $table->decimal('amount', 15, 2)->nullable();
            // checking (asked Safaricom) | waiting (for the treasurer - Safaricom can't be asked) | confirmed | failed
            $table->enum('status', ['checking', 'waiting', 'confirmed', 'failed'])->default('checking');
            $table->string('result', 255)->nullable();
            $table->string('conversation_id', 100)->nullable()->index();
            $table->string('originator_id', 100)->nullable()->unique();
            $table->foreignId('mpesa_payment_id')->nullable()->constrained('mpesa_payments')->nullOnDelete();
            $table->foreignId('claimed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ip', 45)->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();
            $table->index(['territory_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_claims');
    }
};

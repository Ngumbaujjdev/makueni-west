<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Online giving (docs/specs/accounting-spec.md, A10a): each place's payment
 * channels (its Paystack subaccount; PayHero or its own Daraja in A10b), the
 * gifts made on the giving page, and Paystack's payouts recorded once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('territory_id')->constrained('territories')->cascadeOnDelete();
            $table->enum('provider', ['paystack', 'payhero', 'daraja']);
            $table->enum('status', ['pending', 'active', 'off'])->default('pending');
            $table->string('subaccount_code', 60)->nullable()->unique();
            $table->string('bank_code', 30)->nullable();
            $table->string('bank_name', 120)->nullable();
            $table->string('account_number', 40)->nullable();
            $table->string('account_name', 150)->nullable();
            $table->foreignId('settles_into_id')->nullable()->constrained('accounting_accounts')->nullOnDelete();
            $table->text('credentials')->nullable();      // A10b: encrypted
            $table->string('callback_key', 80)->nullable()->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['territory_id', 'provider']);
        });

        Schema::create('gifts', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->foreignId('territory_id')->constrained('territories')->cascadeOnDelete();
            $table->string('purpose', 4);
            $table->decimal('amount', 15, 2);
            $table->string('giver_name', 150)->nullable();
            $table->string('giver_phone', 20)->nullable();
            $table->string('giver_email', 150)->nullable();
            $table->enum('method', ['mpesa', 'paystack']);
            $table->string('provider_ref', 100)->nullable()->unique();
            $table->enum('status', ['pending', 'paid', 'failed', 'abandoned'])->default('pending');
            $table->string('channel', 30)->nullable();
            $table->decimal('fee', 15, 2)->default(0);
            $table->decimal('split', 15, 2)->default(0);
            $table->decimal('net', 15, 2)->default(0);
            $table->foreignId('journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->foreignId('diocese_journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->foreignId('remittance_id')->nullable()->constrained('remittances')->nullOnDelete();
            $table->foreignId('mpesa_request_id')->nullable()->constrained('mpesa_requests')->nullOnDelete();
            $table->foreignId('mpesa_payment_id')->nullable()->constrained('mpesa_payments')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->string('result', 255)->nullable();
            $table->json('raw')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();
            $table->index(['territory_id', 'status']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('paystack_settlements', function (Blueprint $table) {
            $table->id();
            $table->string('settlement_id', 60)->unique();
            $table->foreignId('territory_id')->constrained('territories')->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->date('settled_on');
            $table->foreignId('journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->json('raw')->nullable();
            $table->timestamps();
        });

        // Money in by card (Paystack).
        Schema::table('journals', function (Blueprint $table) {
            $table->enum('method', ['cash', 'mpesa', 'bank', 'cheque', 'card'])->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('journals', function (Blueprint $table) {
            $table->enum('method', ['cash', 'mpesa', 'bank', 'cheque'])->nullable()->change();
        });
        foreach (['paystack_settlements', 'gifts', 'payment_channels'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};

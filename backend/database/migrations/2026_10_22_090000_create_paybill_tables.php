<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The diocese M-Pesa paybill (docs/specs/accounting-spec.md, A8): every
 * callback as it arrived, the payments (one per M-Pesa code), the "Ask to
 * pay" prompts, and the monthly settlements to the places.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20);
            $table->string('kind', 30);
            $table->boolean('key_ok')->default(false);
            $table->string('ip', 45)->nullable();
            $table->json('payload')->nullable();
            $table->enum('status', ['received', 'handled', 'ignored', 'failed'])->default('received');
            $table->string('error', 500)->nullable();
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->timestamps();
            $table->index(['provider', 'created_at']);
        });

        Schema::create('mpesa_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('territory_id')->constrained('territories')->cascadeOnDelete();
            $table->string('account_ref', 20);
            $table->decimal('amount', 15, 2);
            $table->string('phone', 20);
            $table->string('shortcode', 20);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('merchant_request_id', 100)->nullable();
            $table->string('checkout_request_id', 100)->nullable()->unique();
            $table->enum('status', ['pending', 'paid', 'failed'])->default('pending');
            $table->string('result', 255)->nullable();
            $table->unsignedBigInteger('mpesa_payment_id')->nullable();
            $table->timestamps();
            $table->index(['territory_id', 'status']);
        });

        Schema::create('mpesa_payments', function (Blueprint $table) {
            $table->id();
            $table->string('trans_id', 30)->unique();
            $table->enum('kind', ['c2b', 'stk'])->default('c2b');
            $table->string('shortcode', 20);
            $table->decimal('amount', 15, 2);
            $table->string('phone', 60)->nullable();
            $table->string('payer_name', 150)->nullable();
            $table->string('bill_ref', 60)->nullable();
            $table->dateTime('paid_at');
            $table->foreignId('territory_id')->nullable()->constrained('territories')->nullOnDelete();
            $table->string('purpose', 20)->nullable();
            $table->foreignId('account_id')->nullable()->constrained('accounting_accounts')->nullOnDelete();
            $table->foreignId('fund_id')->nullable()->constrained('accounting_funds')->nullOnDelete();
            $table->enum('status', ['posted', 'to_sort', 'returned'])->default('to_sort');
            $table->string('note', 255)->nullable();
            $table->foreignId('diocese_journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->foreignId('place_journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->foreignId('sort_journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->foreignId('return_voucher_id')->nullable()->constrained('payment_vouchers')->nullOnDelete();
            $table->foreignId('sorted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sorted_at')->nullable();
            $table->foreignId('mpesa_request_id')->nullable()->constrained('mpesa_requests')->nullOnDelete();
            $table->json('raw')->nullable();
            $table->timestamps();
            $table->index(['status', 'paid_at']);
            $table->index(['territory_id', 'paid_at']);
        });

        Schema::create('paybill_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('territory_id')->constrained('territories')->cascadeOnDelete();
            $table->char('month', 7);
            $table->decimal('held', 15, 2);
            $table->decimal('share', 15, 2)->default(0);
            $table->decimal('net', 15, 2);
            $table->enum('status', ['prepared', 'paid', 'cancelled'])->default('prepared');
            $table->foreignId('remittance_id')->nullable()->constrained('remittances')->nullOnDelete();
            $table->foreignId('share_remittance_id')->nullable()->constrained('remittances')->nullOnDelete();
            $table->foreignId('diocese_journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->foreignId('place_journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['territory_id', 'month']);
        });

        Schema::table('remittances', function (Blueprint $table) {
            $table->enum('kind', ['share', 'support', 'settlement'])->default('share')->change();
        });
        // Which place a voucher line is for (e.g. paybill money paid out to a church).
        Schema::table('payment_voucher_lines', function (Blueprint $table) {
            $table->foreignId('for_territory_id')->nullable()->after('budget_line_id')->constrained('territories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_voucher_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('for_territory_id');
        });
        Schema::table('remittances', function (Blueprint $table) {
            $table->enum('kind', ['share', 'support'])->default('share')->change();
        });
        foreach (['paybill_settlements', 'mpesa_payments', 'mpesa_requests', 'payment_events'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};

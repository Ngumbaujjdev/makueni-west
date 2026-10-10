<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remittances between levels (docs/specs/accounting-spec.md, A6): a church's
 * share sent up, or support sent down - paid by a voucher in the sender's
 * books, confirmed into the receiver's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remittances', function (Blueprint $table) {
            $table->id();
            $table->string('number', 60);
            $table->foreignId('from_territory_id')->constrained('territories')->cascadeOnDelete();
            $table->foreignId('to_territory_id')->constrained('territories')->cascadeOnDelete();
            $table->enum('kind', ['share', 'support'])->default('share');
            $table->foreignId('budget_deduction_id')->nullable()->constrained('budget_deductions')->nullOnDelete();
            $table->string('purpose', 255);
            $table->decimal('amount', 15, 2);
            $table->enum('status', ['waiting', 'sent', 'queried', 'confirmed', 'cancelled'])->default('waiting');
            $table->foreignId('payment_voucher_id')->nullable()->constrained('payment_vouchers')->nullOnDelete();
            $table->foreignId('sent_journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->date('sent_on')->nullable();
            $table->string('method', 10)->nullable();
            $table->string('reference', 100)->nullable();
            $table->foreignId('into_account_id')->nullable()->constrained('accounting_accounts')->nullOnDelete();
            $table->date('received_on')->nullable();
            $table->foreignId('received_journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('query_reason', 255)->nullable();
            $table->foreignId('queried_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('queried_at')->nullable();
            $table->string('answer', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['from_territory_id', 'number']);
            $table->index(['from_territory_id', 'status']);
            $table->index(['to_territory_id', 'status']);
        });

        Schema::create('remittance_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('remittance_id')->constrained('remittances')->cascadeOnDelete();
            $table->char('month', 7);
            $table->decimal('amount', 15, 2);
            $table->decimal('due', 15, 2)->nullable();
            $table->timestamps();
            $table->index(['remittance_id', 'month']);
        });

        Schema::table('payment_vouchers', function (Blueprint $table) {
            $table->enum('purpose', ['payment', 'imprest_topup', 'advance', 'bill', 'remittance'])->default('payment')->change();
        });
        Schema::table('payment_vouchers', function (Blueprint $table) {
            $table->foreignId('remittance_id')->nullable()->after('supplier_invoice_id')->constrained('remittances')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_vouchers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('remittance_id');
        });
        Schema::table('payment_vouchers', function (Blueprint $table) {
            $table->enum('purpose', ['payment', 'imprest_topup', 'advance', 'bill'])->default('payment')->change();
        });
        Schema::dropIfExists('remittance_lines');
        Schema::dropIfExists('remittances');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounting (docs/specs/accounting-spec.md, A0-A1): the real money of every
 * church, region and diocese in standard double entry - one chart of
 * accounts set by the diocese (places add their own bank and M-Pesa
 * accounts under it), funds, journals and their lines, payment vouchers,
 * monthly periods and document numbering. One set of tables for every level:
 * territory_id says whose books a row is in.
 *
 * Budgets stays the plan and reads its actuals from here: each budget line
 * posts to an account (budget_lines.account_id) and each budget entry has
 * its journal (budget_entries.journal_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('territory_id')->nullable()->constrained('territories')->cascadeOnDelete(); // null = the diocese's standard account
            $table->foreignId('parent_id')->nullable()->constrained('accounting_accounts')->nullOnDelete();
            $table->string('code', 20);
            $table->string('name', 150);
            $table->string('description', 255)->nullable();
            $table->enum('type', ['asset', 'liability', 'fund', 'income', 'expense']);
            $table->string('system_key', 40)->nullable();
            $table->enum('cash_kind', ['cash', 'petty_cash', 'bank', 'mpesa'])->nullable();
            $table->string('bank_name', 100)->nullable();
            $table->string('branch', 100)->nullable();
            $table->string('account_number', 50)->nullable();
            $table->string('mpesa_number', 30)->nullable();
            $table->boolean('is_header')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['territory_id', 'code']);
            $table->index(['type', 'is_active']);
            $table->index('system_key');
        });

        Schema::create('accounting_funds', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            $table->boolean('is_restricted')->default(false);
            $table->foreignId('equity_account_id')->nullable()->constrained('accounting_accounts')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestamps();
        });

        Schema::create('journals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('territory_id')->constrained('territories')->cascadeOnDelete();
            $table->string('number', 60);
            $table->enum('doc_type', ['receipt', 'payment', 'transfer', 'journal', 'reversal']);
            $table->date('date');
            $table->string('narration', 255)->nullable();
            $table->string('party_name', 150)->nullable();
            $table->string('party_phone', 30)->nullable();
            $table->enum('method', ['cash', 'mpesa', 'bank', 'cheque'])->nullable();
            $table->string('reference', 100)->nullable();
            $table->decimal('amount', 15, 2);
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->enum('status', ['posted', 'reversed'])->default('posted');
            $table->foreignId('reverses_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->foreignId('reversed_by_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->string('reverse_reason', 255)->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->unique(['territory_id', 'number']);
            $table->index(['territory_id', 'date']);
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_id')->constrained('journals')->cascadeOnDelete();
            $table->foreignId('territory_id')->constrained('territories')->cascadeOnDelete();
            $table->date('date');
            $table->unsignedSmallInteger('line_no')->default(1);
            $table->foreignId('account_id')->constrained('accounting_accounts');
            $table->foreignId('fund_id')->nullable()->constrained('accounting_funds')->nullOnDelete();
            $table->foreignId('budget_line_id')->nullable()->constrained('budget_lines')->nullOnDelete();
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);
            $table->string('memo', 255)->nullable();
            $table->foreignId('for_territory_id')->nullable()->constrained('territories')->nullOnDelete();
            $table->timestamps();
            $table->index(['territory_id', 'account_id', 'date']);
            $table->index(['territory_id', 'date']);
        });

        Schema::create('payment_vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('territory_id')->constrained('territories')->cascadeOnDelete();
            $table->string('number', 60);
            $table->date('date');
            $table->string('payee_name', 150);
            $table->string('payee_phone', 30)->nullable();
            $table->foreignId('pay_from_account_id')->constrained('accounting_accounts');
            $table->string('narration', 255);
            $table->decimal('amount', 15, 2);
            $table->enum('status', ['prepared', 'authorised', 'paid', 'rejected', 'cancelled'])->default('prepared');
            $table->enum('method', ['cash', 'mpesa', 'bank', 'cheque'])->nullable();
            $table->string('reference', 100)->nullable();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('prepared_at')->nullable();
            $table->foreignId('authorised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('authorised_at')->nullable();
            $table->string('authorise_note', 255)->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->string('reject_reason', 255)->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->date('paid_on')->nullable();
            $table->foreignId('journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->unique(['territory_id', 'number']);
            $table->index(['territory_id', 'status']);
        });

        Schema::create('payment_voucher_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_voucher_id')->constrained('payment_vouchers')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounting_accounts');
            $table->foreignId('fund_id')->nullable()->constrained('accounting_funds')->nullOnDelete();
            $table->foreignId('budget_line_id')->nullable()->constrained('budget_lines')->nullOnDelete();
            $table->string('description', 255)->nullable();
            $table->decimal('amount', 15, 2);
            $table->timestamps();
        });

        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('territory_id')->constrained('territories')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->string('reopen_reason', 255)->nullable();
            $table->timestamps();
            $table->unique(['territory_id', 'year', 'month']);
        });

        Schema::create('accounting_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('territory_id')->constrained('territories')->cascadeOnDelete();
            $table->string('doc_type', 20);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
            $table->unique(['territory_id', 'doc_type', 'year']);
        });

        Schema::table('budget_lines', function (Blueprint $table) {
            $table->foreignId('account_id')->nullable()->after('budget_category_id')->constrained('accounting_accounts')->nullOnDelete();
        });

        Schema::table('budget_entries', function (Blueprint $table) {
            $table->foreignId('journal_id')->nullable()->after('budget_line_item_id')->constrained('journals')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('budget_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('journal_id');
        });
        Schema::table('budget_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('account_id');
        });
        Schema::dropIfExists('accounting_sequences');
        Schema::dropIfExists('accounting_periods');
        Schema::dropIfExists('payment_voucher_lines');
        Schema::dropIfExists('payment_vouchers');
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journals');
        Schema::dropIfExists('accounting_funds');
        Schema::dropIfExists('accounting_accounts');
    }
};

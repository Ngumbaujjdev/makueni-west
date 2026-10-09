<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounting A2 (docs/specs/accounting-spec.md): proving the books right.
 * Cash counts against the book, bank and M-Pesa reconciliations against the
 * statement (with its imported lines and the book lines it clears), each
 * place's settings for one of its money accounts (the petty cash float, who
 * keeps it, how its bank CSV reads), petty cash vouchers and float top-ups.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_place_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('territory_id')->constrained('territories')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounting_accounts')->cascadeOnDelete();
            $table->decimal('imprest_float', 15, 2)->nullable();
            $table->foreignId('custodian_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('statement_mapping')->nullable();
            $table->timestamps();
            $table->unique(['territory_id', 'account_id']);
        });

        Schema::create('cash_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('territory_id')->constrained('territories')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounting_accounts');
            $table->date('counted_on');
            $table->json('denominations')->nullable();
            $table->decimal('counted_total', 15, 2);
            $table->decimal('book_balance', 15, 2);
            $table->decimal('difference', 15, 2);
            $table->string('reason', 255)->nullable();
            $table->boolean('is_surprise')->default(false);
            $table->enum('status', ['balanced', 'waiting', 'approved', 'rejected']);
            $table->foreignId('counted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('reject_reason', 255)->nullable();
            $table->foreignId('journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->timestamps();
            $table->index(['territory_id', 'account_id', 'counted_on']);
        });

        Schema::create('bank_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('territory_id')->constrained('territories')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounting_accounts');
            $table->date('statement_date');
            $table->decimal('statement_balance', 15, 2);
            $table->decimal('book_balance', 15, 2)->default(0);
            $table->decimal('in_transit', 15, 2)->default(0);
            $table->decimal('unpresented', 15, 2)->default(0);
            $table->decimal('difference', 15, 2)->default(0);
            $table->enum('status', ['draft', 'submitted', 'approved', 'returned'])->default('draft');
            $table->string('notes', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('prepared_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('return_reason', 255)->nullable();
            $table->timestamps();
            $table->index(['territory_id', 'account_id', 'statement_date'], 'bank_recs_place_account_date');
        });

        Schema::create('bank_statement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reconciliation_id')->constrained('bank_reconciliations')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no')->default(0);
            $table->date('date');
            $table->string('description', 255)->nullable();
            $table->string('reference', 100)->nullable();
            $table->decimal('money_in', 15, 2)->default(0);
            $table->decimal('money_out', 15, 2)->default(0);
            $table->decimal('balance', 15, 2)->nullable();
            $table->foreignId('matched_line_id')->nullable()->constrained('journal_lines')->nullOnDelete();
            $table->enum('status', ['unmatched', 'matched', 'added', 'ignored'])->default('unmatched');
            $table->timestamps();
        });

        Schema::table('journal_lines', function (Blueprint $table) {
            $table->date('cleared_on')->nullable()->after('for_territory_id');
            $table->foreignId('reconciliation_id')->nullable()->after('cleared_on')->constrained('bank_reconciliations')->nullOnDelete();
        });

        Schema::table('journals', function (Blueprint $table) {
            $table->enum('doc_type', ['receipt', 'payment', 'transfer', 'journal', 'reversal', 'petty_cash'])->change();
        });

        Schema::table('payment_vouchers', function (Blueprint $table) {
            $table->enum('purpose', ['payment', 'imprest_topup'])->default('payment')->after('narration');
        });
    }

    public function down(): void
    {
        Schema::table('payment_vouchers', function (Blueprint $table) {
            $table->dropColumn('purpose');
        });
        Schema::table('journals', function (Blueprint $table) {
            $table->enum('doc_type', ['receipt', 'payment', 'transfer', 'journal', 'reversal'])->change();
        });
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reconciliation_id');
            $table->dropColumn('cleared_on');
        });
        Schema::dropIfExists('bank_statement_lines');
        Schema::dropIfExists('bank_reconciliations');
        Schema::dropIfExists('cash_counts');
        Schema::dropIfExists('accounting_place_accounts');
    }
};

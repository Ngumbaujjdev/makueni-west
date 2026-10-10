<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Year-end close (docs/specs/accounting-spec.md, A9): one row per place and
 * year, with the closing journal that moved each fund's surplus into the
 * fund. Also tags the paying side of past remittances with the place they
 * went to, so a consolidated statement can take out what moved between
 * places.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('territory_id')->constrained('territories')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->foreignId('closing_journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->decimal('surplus', 15, 2)->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->string('reopen_reason')->nullable();
            $table->timestamps();
            $table->unique(['territory_id', 'year']);
        });

        Schema::table('journals', function (Blueprint $table) {
            $table->enum('doc_type', ['receipt', 'payment', 'transfer', 'journal', 'reversal', 'petty_cash', 'bill', 'payroll', 'closing'])->change();
        });

        // The paying side of a remittance names the place it went to (the receiving side always did).
        foreach (DB::table('remittances')->whereNotNull('payment_voucher_id')->get(['payment_voucher_id', 'to_territory_id']) as $r) {
            DB::table('payment_voucher_lines')->where('payment_voucher_id', $r->payment_voucher_id)->whereNull('for_territory_id')->update(['for_territory_id' => $r->to_territory_id]);
            $journal = DB::table('payment_vouchers')->where('id', $r->payment_voucher_id)->value('journal_id');
            if ($journal) {
                DB::table('journal_lines')->where('journal_id', $journal)->where('debit', '>', 0)->whereNull('for_territory_id')->update(['for_territory_id' => $r->to_territory_id]);
                DB::table('journal_lines')->whereIn('journal_id', DB::table('journals')->where('reverses_id', $journal)->pluck('id'))->where('credit', '>', 0)->whereNull('for_territory_id')
                    ->update(['for_territory_id' => $r->to_territory_id]);
            }
        }
    }

    public function down(): void
    {
        DB::table('journals')->where('doc_type', 'closing')->exists() && throw new RuntimeException('Years have been closed - reopen them first.');
        Schema::table('journals', function (Blueprint $table) {
            $table->enum('doc_type', ['receipt', 'payment', 'transfer', 'journal', 'reversal', 'petty_cash', 'bill', 'payroll'])->change();
        });
        Schema::dropIfExists('accounting_years');
    }
};

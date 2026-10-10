<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Airtel Money (docs/specs/accounting-spec.md, Redesign R2b): a way to receive
 * and pay, and a kind of money account (1160 Airtel Money accounts).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journals', fn (Blueprint $t) => $t->enum('method', ['cash', 'mpesa', 'bank', 'cheque', 'card', 'airtel'])->nullable()->change());
        Schema::table('payment_vouchers', fn (Blueprint $t) => $t->enum('method', ['cash', 'mpesa', 'bank', 'cheque', 'airtel'])->nullable()->change());
        Schema::table('budget_entries', fn (Blueprint $t) => $t->enum('method', ['cash', 'mpesa', 'bank', 'cheque', 'airtel'])->nullable()->change());
        Schema::table('accounting_accounts', fn (Blueprint $t) => $t->enum('cash_kind', ['cash', 'petty_cash', 'bank', 'mpesa', 'airtel'])->nullable()->change());
    }

    public function down(): void
    {
        // Anything recorded as Airtel Money falls back to M-Pesa (the nearest kind) before the choice goes.
        foreach (['journals', 'payment_vouchers', 'budget_entries'] as $table) {
            DB::table($table)->where('method', 'airtel')->update(['method' => 'mpesa']);
        }
        DB::table('accounting_accounts')->where('cash_kind', 'airtel')->update(['cash_kind' => 'mpesa']);
        Schema::table('journals', fn (Blueprint $t) => $t->enum('method', ['cash', 'mpesa', 'bank', 'cheque', 'card'])->nullable()->change());
        Schema::table('payment_vouchers', fn (Blueprint $t) => $t->enum('method', ['cash', 'mpesa', 'bank', 'cheque'])->nullable()->change());
        Schema::table('budget_entries', fn (Blueprint $t) => $t->enum('method', ['cash', 'mpesa', 'bank', 'cheque'])->nullable()->change());
        Schema::table('accounting_accounts', fn (Blueprint $t) => $t->enum('cash_kind', ['cash', 'petty_cash', 'bank', 'mpesa'])->nullable()->change());
    }
};

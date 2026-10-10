<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where money goes, in a form a treasurer can pay from (docs/specs/accounting-spec.md,
 * payee details): M-Pesa or Airtel number, paybill + account, till, bank +
 * account, or cash - on suppliers, staff, requisitions and vouchers (a copy).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['suppliers', 'employees', 'payslips', 'requisitions', 'payment_vouchers'] as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->json('payee')->nullable();
            });
        }
        // More ways to be paid than M-Pesa, bank or cash.
        foreach (['employees', 'payslips'] as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->string('pay_method', 20)->default('mpesa')->change();
            });
        }
    }

    public function down(): void
    {
        foreach (['suppliers', 'employees', 'payslips', 'requisitions', 'payment_vouchers'] as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->dropColumn('payee');
            });
        }
    }
};

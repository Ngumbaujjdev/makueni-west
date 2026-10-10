<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll without statutory deductions (docs/specs/accounting-spec.md, A7):
 * the churches don't deduct PAYE, NSSF, SHIF or the Housing Levy, so a
 * payslip is pay less any other deduction (a SACCO, a loan) - nothing more.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->dropColumn(['statutory', 'nssf', 'shif', 'ahl', 'taxable', 'paye', 'employer_nssf', 'employer_ahl', 'manual']);
        });
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['statutory', 'nssf_no', 'shif_no']);
        });
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropColumn('employer');
        });
        Schema::table('payroll_payments', function (Blueprint $table) {
            $table->enum('kind', ['net'])->default('net')->change();
        });
        // 2300 no longer holds statutory deductions - only if its words are still the standard ones.
        DB::table('accounting_accounts')->whereNull('territory_id')->where('code', '2300')
            ->where('description', 'PAYE, NSSF, SHIF and Housing Levy held to pay over')
            ->update(['description' => 'Deductions held from pay (e.g. SACCO, loans) to pay over']);
    }

    public function down(): void
    {
        Schema::table('payroll_payments', function (Blueprint $table) {
            $table->enum('kind', ['net', 'paye', 'nssf', 'shif', 'ahl'])->change();
        });
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->decimal('employer', 15, 2)->default(0)->after('net');
        });
        Schema::table('employees', function (Blueprint $table) {
            $table->boolean('statutory')->default(true)->after('allowances');
            $table->text('nssf_no')->nullable()->after('kra_pin');
            $table->text('shif_no')->nullable()->after('nssf_no');
        });
        Schema::table('payslips', function (Blueprint $table) {
            $table->boolean('statutory')->default(true)->after('pay_to');
            foreach (['nssf', 'shif', 'ahl', 'taxable', 'paye'] as $c) {
                $table->decimal($c, 15, 2)->default(0)->after('gross');
            }
            $table->decimal('employer_nssf', 15, 2)->default(0)->after('net');
            $table->decimal('employer_ahl', 15, 2)->default(0)->after('employer_nssf');
            $table->boolean('manual')->default(false)->after('employer_ahl');
        });
    }
};

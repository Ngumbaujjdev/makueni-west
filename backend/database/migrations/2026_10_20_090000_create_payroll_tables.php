<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll (docs/specs/accounting-spec.md, A7): the people a place pays, a
 * monthly run with its payslips, and what was paid out of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('territory_id')->constrained('territories')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 150);
            $table->string('phone', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('position', 100)->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->enum('pay_method', ['mpesa', 'bank', 'cash'])->default('mpesa');
            $table->string('pay_to', 150)->nullable();
            $table->decimal('basic_pay', 15, 2)->default(0);
            $table->json('allowances')->nullable();
            $table->boolean('statutory')->default(true);
            // Personal numbers - encrypted at rest, only ever shown masked.
            $table->text('id_number')->nullable();
            $table->text('kra_pin')->nullable();
            $table->text('nssf_no')->nullable();
            $table->text('shif_no')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['territory_id', 'is_active']);
        });

        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('territory_id')->constrained('territories')->cascadeOnDelete();
            $table->char('month', 7);
            $table->enum('status', ['draft', 'submitted', 'returned', 'posted', 'paid', 'cancelled'])->default('draft');
            $table->decimal('gross', 15, 2)->default(0);
            $table->decimal('deductions', 15, 2)->default(0);
            $table->decimal('net', 15, 2)->default(0);
            $table->decimal('employer', 15, 2)->default(0);
            $table->foreignId('journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('decision_note', 255)->nullable();
            $table->timestamps();
            $table->index(['territory_id', 'month']);
        });

        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained('payroll_runs')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->string('name', 150);
            $table->string('position', 100)->nullable();
            $table->enum('pay_method', ['mpesa', 'bank', 'cash'])->default('mpesa');
            $table->string('pay_to', 150)->nullable();
            $table->boolean('statutory')->default(true);
            $table->decimal('basic', 15, 2)->default(0);
            $table->json('allowances')->nullable();
            $table->decimal('gross', 15, 2)->default(0);
            $table->decimal('nssf', 15, 2)->default(0);
            $table->decimal('shif', 15, 2)->default(0);
            $table->decimal('ahl', 15, 2)->default(0);
            $table->decimal('taxable', 15, 2)->default(0);
            $table->decimal('paye', 15, 2)->default(0);
            $table->decimal('other', 15, 2)->default(0);
            $table->string('other_note', 150)->nullable();
            $table->decimal('total_deductions', 15, 2)->default(0);
            $table->decimal('net', 15, 2)->default(0);
            $table->decimal('employer_nssf', 15, 2)->default(0);
            $table->decimal('employer_ahl', 15, 2)->default(0);
            $table->boolean('manual')->default(false);
            $table->string('reference', 60)->nullable();
            $table->timestamps();
            $table->unique(['payroll_run_id', 'employee_id']);
        });

        Schema::create('payroll_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained('payroll_runs')->cascadeOnDelete();
            $table->enum('kind', ['net', 'paye', 'nssf', 'shif', 'ahl']);
            $table->decimal('amount', 15, 2);
            $table->foreignId('payment_voucher_id')->nullable()->constrained('payment_vouchers')->nullOnDelete();
            $table->timestamps();
            $table->index(['payroll_run_id', 'kind']);
        });

        Schema::table('payment_vouchers', function (Blueprint $table) {
            $table->enum('purpose', ['payment', 'imprest_topup', 'advance', 'bill', 'remittance', 'payroll'])->default('payment')->change();
        });
        Schema::table('payment_vouchers', function (Blueprint $table) {
            $table->foreignId('payroll_payment_id')->nullable()->after('remittance_id')->constrained('payroll_payments')->nullOnDelete();
        });
        Schema::table('journals', function (Blueprint $table) {
            $table->enum('doc_type', ['receipt', 'payment', 'transfer', 'journal', 'reversal', 'petty_cash', 'bill', 'payroll'])->change();
        });
    }

    public function down(): void
    {
        Schema::table('journals', function (Blueprint $table) {
            $table->enum('doc_type', ['receipt', 'payment', 'transfer', 'journal', 'reversal', 'petty_cash', 'bill'])->change();
        });
        Schema::table('payment_vouchers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payroll_payment_id');
        });
        Schema::table('payment_vouchers', function (Blueprint $table) {
            $table->enum('purpose', ['payment', 'imprest_topup', 'advance', 'bill', 'remittance'])->default('payment')->change();
        });
        foreach (['payroll_payments', 'payslips', 'payroll_runs', 'employees'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};

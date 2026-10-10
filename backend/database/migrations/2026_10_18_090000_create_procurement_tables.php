<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Procurement (docs/specs/accounting-spec.md, A5): suppliers, quotations on
 * a purchase requisition, the local purchase order, goods received and the
 * supplier's bill - matched three ways, then paid by a voucher.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('territory_id')->constrained('territories')->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('phone', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('kra_pin', 20)->nullable();
            $table->string('pay_details', 255)->nullable();
            $table->string('notes', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['territory_id', 'is_active']);
        });

        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requisition_id')->constrained('requisitions')->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('notes', 255)->nullable();
            $table->boolean('chosen')->default(false);
            $table->string('chosen_reason', 255)->nullable();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('territory_id')->constrained('territories')->cascadeOnDelete();
            $table->string('number', 60);
            $table->foreignId('requisition_id')->unique()->constrained('requisitions')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->date('date');
            $table->date('deliver_by')->nullable();
            $table->string('notes', 500)->nullable();
            $table->decimal('amount', 15, 2);
            $table->enum('status', ['issued', 'part_received', 'received', 'closed', 'cancelled'])->default('issued');
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason', 255)->nullable();
            $table->timestamps();
            $table->unique(['territory_id', 'number']);
            $table->index(['territory_id', 'status']);
        });

        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->string('description', 255);
            $table->decimal('quantity', 12, 2);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('amount', 15, 2);
            $table->foreignId('account_id')->constrained('accounting_accounts')->restrictOnDelete();
            $table->foreignId('fund_id')->nullable()->constrained('accounting_funds')->nullOnDelete();
            $table->foreignId('budget_line_id')->nullable()->constrained('budget_lines')->nullOnDelete();
            $table->boolean('is_asset')->default(false);
            $table->decimal('received_qty', 12, 2)->default(0);
            $table->decimal('billed_qty', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('goods_received', function (Blueprint $table) {
            $table->id();
            $table->foreignId('territory_id')->constrained('territories')->cascadeOnDelete();
            $table->string('number', 60);
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->date('date');
            $table->string('notes', 255)->nullable();
            $table->enum('status', ['received', 'undone'])->default('received');
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('undone_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('undone_at')->nullable();
            $table->timestamps();
            $table->unique(['territory_id', 'number']);
        });

        Schema::create('goods_received_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_received_id')->constrained('goods_received')->cascadeOnDelete();
            $table->foreignId('purchase_order_line_id')->constrained('purchase_order_lines')->cascadeOnDelete();
            $table->decimal('quantity', 12, 2);
            $table->foreignId('equipment_id')->nullable()->constrained('equipment')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('supplier_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('territory_id')->constrained('territories')->cascadeOnDelete();
            $table->string('number', 60);
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->string('supplier_ref', 60);
            $table->date('date');
            $table->date('due_on')->nullable();
            $table->decimal('amount', 15, 2);
            $table->enum('status', ['posted', 'paid', 'reversed'])->default('posted');
            $table->foreignId('journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->foreignId('payment_voucher_id')->nullable()->constrained('payment_vouchers')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['territory_id', 'number']);
            $table->index(['territory_id', 'status']);
        });

        Schema::create('supplier_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_invoice_id')->constrained('supplier_invoices')->cascadeOnDelete();
            $table->foreignId('purchase_order_line_id')->constrained('purchase_order_lines')->cascadeOnDelete();
            $table->string('description', 255);
            $table->decimal('quantity', 12, 2);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('amount', 15, 2);
            $table->timestamps();
        });

        Schema::table('requisitions', function (Blueprint $table) {
            $table->enum('status', ['submitted', 'approved', 'returned', 'rejected', 'ordered', 'paid', 'cancelled'])->default('submitted')->change();
        });
        Schema::table('payment_vouchers', function (Blueprint $table) {
            $table->enum('purpose', ['payment', 'imprest_topup', 'advance', 'bill'])->default('payment')->change();
        });
        Schema::table('payment_vouchers', function (Blueprint $table) {
            $table->foreignId('supplier_invoice_id')->nullable()->after('requisition_id')->constrained('supplier_invoices')->nullOnDelete();
        });
        Schema::table('journals', function (Blueprint $table) {
            $table->enum('doc_type', ['receipt', 'payment', 'transfer', 'journal', 'reversal', 'petty_cash', 'bill'])->change();
        });
    }

    public function down(): void
    {
        Schema::table('journals', function (Blueprint $table) {
            $table->enum('doc_type', ['receipt', 'payment', 'transfer', 'journal', 'reversal', 'petty_cash'])->change();
        });
        Schema::table('payment_vouchers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supplier_invoice_id');
        });
        Schema::table('payment_vouchers', function (Blueprint $table) {
            $table->enum('purpose', ['payment', 'imprest_topup', 'advance'])->default('payment')->change();
        });
        Schema::table('requisitions', function (Blueprint $table) {
            $table->enum('status', ['submitted', 'approved', 'returned', 'rejected', 'paid', 'cancelled'])->default('submitted')->change();
        });
        foreach (['supplier_invoice_lines', 'supplier_invoices', 'goods_received_lines', 'goods_received', 'purchase_order_lines', 'purchase_orders', 'quotations', 'suppliers'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};

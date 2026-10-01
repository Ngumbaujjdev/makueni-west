<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money that actually came in or went out against a budget line - what a
 * pastor records under Spending (docs/specs/budgets-spec.md). Each line's
 * received/spent amount (budget_line_items.actual_amount) is the sum of its
 * entries. A line the budget didn't plan for can still be spent on; it is
 * added to the budget as "unplanned" (planned 0).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained('budgets')->cascadeOnDelete();
            $table->foreignId('budget_line_item_id')->constrained('budget_line_items')->cascadeOnDelete();
            $table->enum('direction', ['in', 'out']);
            $table->decimal('amount', 15, 2);
            $table->date('entry_date');
            $table->string('description', 255);
            $table->string('counterparty', 255)->nullable();
            $table->enum('method', ['cash', 'mpesa', 'bank', 'cheque'])->nullable();
            $table->string('reference', 100)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['budget_id', 'entry_date']);
        });

        Schema::table('budget_line_items', function (Blueprint $table) {
            $table->boolean('is_unplanned')->default(false)->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('budget_line_items', function (Blueprint $table) {
            $table->dropColumn('is_unplanned');
        });
        Schema::dropIfExists('budget_entries');
    }
};

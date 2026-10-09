<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounting A3 (docs/specs/accounting-spec.md): the Sunday collection -
 * counted by one person, confirmed by another, posted as one official
 * receipt per fund, then banked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('territory_id')->constrained('territories')->cascadeOnDelete();
            $table->date('date');
            $table->string('title', 150);
            $table->foreignId('attendance_record_id')->nullable()->constrained('church_attendance_records')->nullOnDelete();
            $table->foreignId('gathering_type_id')->nullable()->constrained('gathering_types')->nullOnDelete();
            $table->foreignId('cash_account_id')->constrained('accounting_accounts');
            $table->foreignId('mpesa_account_id')->nullable()->constrained('accounting_accounts')->nullOnDelete();
            $table->json('denominations')->nullable();
            $table->decimal('cash_total', 15, 2)->default(0);
            $table->decimal('mpesa_total', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->json('witnesses')->nullable();
            $table->string('notes', 255)->nullable();
            $table->enum('status', ['counted', 'returned', 'posted', 'reversed'])->default('counted');
            $table->foreignId('counted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('return_reason', 255)->nullable();
            $table->foreignId('journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->foreignId('banking_journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->timestamps();
            $table->index(['territory_id', 'date'], 'collections_place_date');
            $table->index(['territory_id', 'status'], 'collections_place_status');
        });

        Schema::create('collection_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_id')->constrained('collections')->cascadeOnDelete();
            $table->string('label', 100);
            $table->foreignId('account_id')->constrained('accounting_accounts');
            $table->foreignId('fund_id')->nullable()->constrained('accounting_funds')->nullOnDelete();
            $table->decimal('cash_amount', 15, 2)->default(0);
            $table->decimal('mpesa_amount', 15, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_lines');
        Schema::dropIfExists('collections');
    }
};

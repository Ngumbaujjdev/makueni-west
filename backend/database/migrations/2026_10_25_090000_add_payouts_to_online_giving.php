<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Getting paid (docs/specs/accounting-spec.md, A10c): a place's request for
 * its own Paystack and the diocese's check, every Paystack payout with its
 * status and the gifts it paid, and refunds and disputes on a gift.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_channels', function (Blueprint $table) {
            $table->json('request')->nullable()->after('callback_key');
            $table->foreignId('requested_by')->nullable()->after('request')->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->nullable()->after('requested_by');
            $table->string('review_note', 255)->nullable()->after('requested_at');
            $table->foreignId('checked_by')->nullable()->after('review_note')->constrained('users')->nullOnDelete();
            $table->timestamp('checked_at')->nullable()->after('checked_by');
        });

        Schema::table('paystack_settlements', function (Blueprint $table) {
            $table->string('status', 20)->default('success')->after('territory_id');
            $table->decimal('gross', 15, 2)->default(0)->after('amount');
            $table->decimal('fees', 15, 2)->default(0)->after('gross');
            $table->date('covers_from')->nullable()->after('settled_on');
            $table->date('covers_to')->nullable()->after('covers_from');
            $table->enum('matched', ['adds_up', 'closest', 'none'])->default('none')->after('covers_to');
            $table->boolean('main')->default(false)->after('matched');   // the diocese's main account, not a subaccount
        });

        Schema::table('gifts', function (Blueprint $table) {
            $table->enum('status', ['pending', 'paid', 'failed', 'abandoned', 'refunded'])->default('pending')->change();
            $table->foreignId('settlement_id')->nullable()->after('remittance_id')->constrained('paystack_settlements')->nullOnDelete();
            $table->foreignId('main_settlement_id')->nullable()->after('settlement_id')->constrained('paystack_settlements')->nullOnDelete();
            $table->decimal('refunded_amount', 15, 2)->default(0)->after('net');
            $table->timestamp('refunded_at')->nullable()->after('paid_at');
            $table->timestamp('disputed_at')->nullable()->after('refunded_at');
        });
    }

    public function down(): void
    {
        Schema::table('gifts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('settlement_id');
            $table->dropConstrainedForeignId('main_settlement_id');
            $table->dropColumn(['refunded_amount', 'refunded_at', 'disputed_at']);
            $table->enum('status', ['pending', 'paid', 'failed', 'abandoned'])->default('pending')->change();
        });
        Schema::table('paystack_settlements', function (Blueprint $table) {
            $table->dropColumn(['status', 'gross', 'fees', 'covers_from', 'covers_to', 'matched', 'main']);
        });
        Schema::table('payment_channels', function (Blueprint $table) {
            $table->dropConstrainedForeignId('requested_by');
            $table->dropConstrainedForeignId('checked_by');
            $table->dropColumn(['request', 'requested_at', 'review_note', 'checked_at']);
        });
    }
};

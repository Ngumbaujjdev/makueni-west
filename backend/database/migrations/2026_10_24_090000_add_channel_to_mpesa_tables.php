<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A church's own paybill (docs/specs/accounting-spec.md, A10b): which
 * paybill an M-Pesa payment or prompt went through - null is the diocese
 * paybill (A8), otherwise the church's PayHero or own Daraja channel.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['mpesa_payments', 'mpesa_requests'] as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->foreignId('channel_id')->nullable()->after('id')->constrained('payment_channels')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['mpesa_payments', 'mpesa_requests'] as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->dropConstrainedForeignId('channel_id');
            });
        }
    }
};

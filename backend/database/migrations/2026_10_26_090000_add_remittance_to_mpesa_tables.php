<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paying a share by M-Pesa (docs/specs/accounting-spec.md, A6b): which
 * remittance an M-Pesa prompt or payment into the diocese paybill paid.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['mpesa_requests', 'mpesa_payments'] as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->foreignId('remittance_id')->nullable()->after('channel_id')->constrained('remittances')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['mpesa_requests', 'mpesa_payments'] as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->dropConstrainedForeignId('remittance_id');
            });
        }
    }
};

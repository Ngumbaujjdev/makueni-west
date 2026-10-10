<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Giving options and funds at every level (docs/specs/accounting-spec.md, A11):
 * a fund can belong to a place (and the places below it), and a gift or paybill
 * payment for an option a place above owns is booked in the owner's books -
 * owner_territory_id says whose; territory_id stays where it was collected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_funds', function (Blueprint $table) {
            $table->foreignId('territory_id')->nullable()->after('id')->constrained('territories')->restrictOnDelete();
            $table->enum('reach', ['self', 'below'])->default('below')->after('territory_id');
            $table->dropUnique(['code']);
            $table->unique(['territory_id', 'code']);
        });
        Schema::table('gifts', function (Blueprint $table) {
            $table->foreignId('owner_territory_id')->nullable()->after('territory_id')->constrained('territories')->nullOnDelete();
        });
        Schema::table('mpesa_payments', function (Blueprint $table) {
            $table->foreignId('owner_territory_id')->nullable()->after('territory_id')->constrained('territories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mpesa_payments', fn (Blueprint $table) => $table->dropConstrainedForeignId('owner_territory_id'));
        Schema::table('gifts', fn (Blueprint $table) => $table->dropConstrainedForeignId('owner_territory_id'));
        Schema::table('accounting_funds', function (Blueprint $table) {
            $table->dropUnique(['territory_id', 'code']);
            $table->unique(['code']);
            $table->dropConstrainedForeignId('territory_id');
            $table->dropColumn('reach');
        });
    }
};

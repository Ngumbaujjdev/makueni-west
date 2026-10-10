<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Giving options (docs/specs/accounting-spec.md, A11): what a giver gives for -
 * Tithe, Offering, a region's conference... - and where it is booked. The
 * standard ones (no owner) are kept by whichever place collects them; the
 * five that used to be fixed in code keep their keys (T, O, TH, B, K), so
 * every gift, claim and payment already made keeps its name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('giving_purposes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('territory_id')->nullable()->constrained('territories')->restrictOnDelete();
            $table->enum('reach', ['self', 'below'])->default('below');
            $table->string('key', 8)->unique();
            $table->string('label', 40);
            $table->string('suffix', 6)->unique();
            $table->json('words')->nullable();
            $table->foreignId('account_id')->constrained('accounting_accounts')->restrictOnDelete();
            $table->foreignId('fund_id')->nullable()->constrained('accounting_funds')->nullOnDelete();
            $table->string('icon', 40)->nullable();
            $table->string('colour', 20)->nullable();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['territory_id', 'is_active']);
        });
        // A new option's key can be longer than the old one- and two-letter ones.
        Schema::table('gifts', fn (Blueprint $table) => $table->string('purpose', 8)->change());
        Schema::table('payment_claims', fn (Blueprint $table) => $table->string('purpose', 8)->change());
    }

    public function down(): void
    {
        Schema::dropIfExists('giving_purposes');
        Schema::table('gifts', fn (Blueprint $table) => $table->string('purpose', 4)->change());
        Schema::table('payment_claims', fn (Blueprint $table) => $table->string('purpose', 4)->change());
    }
};

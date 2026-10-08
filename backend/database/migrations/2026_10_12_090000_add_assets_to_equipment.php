<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Equipment as the church's assets (docs/specs/people-and-care-spec.md, P5
 * round 2): an asset number to label it with, where it was bought, the
 * Budgets entry that paid for it, and its photos (receipts are media). Loans
 * can now be asked for first - requested, then out (or declined), then
 * returned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipment', function (Blueprint $t) {
            $t->string('asset_no', 20)->nullable()->after('territory_id');
            $t->string('supplier', 120)->nullable()->after('value');
            $t->foreignId('budget_entry_id')->nullable()->after('supplier')->constrained('budget_entries')->nullOnDelete();
            $t->unique(['territory_id', 'asset_no']);
        });

        Schema::create('equipment_photos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $t->string('path');
            $t->string('thumb_path')->nullable();
            $t->unsignedInteger('width')->nullable();
            $t->unsignedInteger('height')->nullable();
            $t->unsignedInteger('bytes')->nullable();
            $t->unsignedSmallInteger('position')->default(0);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['equipment_id', 'position']);
        });

        Schema::table('equipment_loans', function (Blueprint $t) {
            $t->string('status', 10)->default('out')->after('quantity');
            $t->foreignId('requested_by')->nullable()->after('by')->constrained('users')->nullOnDelete();
            $t->foreignId('decided_by')->nullable()->after('requested_by')->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable()->after('decided_by');
            $t->string('decline_reason', 200)->nullable()->after('decided_at');
            $t->index(['equipment_id', 'status']);
        });
        DB::table('equipment_loans')->whereNotNull('returned_on')->update(['status' => 'returned']);

        // Number what is already there, per church, in the order it was added.
        $n = [];
        foreach (DB::table('equipment')->orderBy('id')->get(['id', 'territory_id']) as $e) {
            $n[$e->territory_id] = ($n[$e->territory_id] ?? 0) + 1;
            DB::table('equipment')->where('id', $e->id)->update(['asset_no' => sprintf('A-%04d', $n[$e->territory_id])]);
        }
    }

    public function down(): void
    {
        Schema::table('equipment_loans', function (Blueprint $t) {
            $t->dropIndex(['equipment_id', 'status']);
            $t->dropConstrainedForeignId('requested_by');
            $t->dropConstrainedForeignId('decided_by');
            $t->dropColumn(['status', 'decided_at', 'decline_reason']);
        });
        Schema::dropIfExists('equipment_photos');
        Schema::table('equipment', function (Blueprint $t) {
            $t->dropUnique(['territory_id', 'asset_no']);
            $t->dropConstrainedForeignId('budget_entry_id');
            $t->dropColumn(['asset_no', 'supplier']);
        });
    }
};

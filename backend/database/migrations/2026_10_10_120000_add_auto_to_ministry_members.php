<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * People & care, P4 round 2: the register keeps some ministry places itself -
 * every Sunday-school child is in "Children & Sunday school". Those rows are
 * marked auto, so they come and go with the person's congregation, and are
 * not taken out by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ministry_members', function (Blueprint $table) {
            $table->boolean('auto')->default(false)->after('joined_on');
        });
    }

    public function down(): void
    {
        Schema::table('ministry_members', function (Blueprint $table) {
            $table->dropColumn('auto');
        });
    }
};

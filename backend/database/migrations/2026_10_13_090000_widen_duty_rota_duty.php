<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A church names its own duties in Settings > Facilities (P5 round 3) - the
 * key is made from the name, so it needs more room than the five built-in
 * ones did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('duty_rota', fn (Blueprint $t) => $t->string('duty', 40)->change());
    }

    public function down(): void
    {
        Schema::table('duty_rota', fn (Blueprint $t) => $t->string('duty', 12)->change());
    }
};

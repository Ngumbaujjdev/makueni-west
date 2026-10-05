<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One phone number per person (docs/specs/settings-spec.md, S6a). The key is
 * the number's last 9 digits, so "+254 712 345 678" and "0712345678" clash.
 * If two people already share a number, the first keeps the key and the
 * others are logged - the unique index then protects everything new.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->char('phone_key', 9)->nullable()->after('phone');
        });

        $taken = [];
        DB::table('users')->whereNotNull('phone')->orderBy('id')->select('id', 'phone')->each(function ($user) use (&$taken) {
            $digits = preg_replace('/\D+/', '', (string) $user->phone);
            $key = strlen($digits) >= 9 ? substr($digits, -9) : null;
            if (! $key) {
                return;
            }
            if (isset($taken[$key])) {
                logger()->warning("users.phone_key: user {$user->id} shares a phone with user {$taken[$key]} - left without a key");

                return;
            }
            $taken[$key] = $user->id;
            DB::table('users')->where('id', $user->id)->update(['phone_key' => $key]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unique('phone_key');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['phone_key']);
            $table->dropColumn('phone_key');
        });
    }
};

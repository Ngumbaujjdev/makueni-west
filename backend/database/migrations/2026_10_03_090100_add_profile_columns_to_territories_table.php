<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a place's Settings > Profile needs that territories didn't hold
 * (docs/specs/settings-spec.md): a website, a logo and a sub-county, and a
 * phone column wide enough for "+254 7xx xxx xxx" formats.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('territories', function (Blueprint $table) {
            $table->string('website', 255)->nullable()->after('email');
            $table->string('logo_path', 255)->nullable()->after('website');
            $table->string('sub_county', 100)->nullable()->after('county');
            $table->string('phone', 30)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('territories', function (Blueprint $table) {
            $table->dropColumn(['website', 'logo_path', 'sub_county']);
            $table->string('phone', 20)->nullable()->change();
        });
    }
};

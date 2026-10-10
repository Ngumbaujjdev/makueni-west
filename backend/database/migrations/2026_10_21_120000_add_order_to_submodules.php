<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A module's pages in a set order on the menu (the sidebar and the tab bar),
 * not A-Z. 0 means "no order set": a module whose pages are all 0 stays A-Z.
 * The access seeders write each page's place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('submodules', function (Blueprint $table) {
            $table->unsignedSmallInteger('order')->default(0)->after('title');
            $table->index(['module_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::table('submodules', function (Blueprint $table) {
            $table->dropIndex(['module_id', 'order']);
            $table->dropColumn('order');
        });
    }
};

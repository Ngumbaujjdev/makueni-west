<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Templates (docs/specs/messages-spec.md, L5c): the diocese (or a region)
 * shares templates with every place below; a place makes its own copy, and
 * can put it back to the shared text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_templates', function (Blueprint $table) {
            $table->boolean('shared_below')->default(false)->after('body');
            $table->foreignId('copied_from_id')->nullable()->after('shared_below')->constrained('message_templates')->nullOnDelete();
            $table->index(['territory_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::table('message_templates', function (Blueprint $table) {
            $table->dropIndex(['territory_id', 'channel']);
            $table->dropConstrainedForeignId('copied_from_id');
            $table->dropColumn('shared_below');
        });
    }
};

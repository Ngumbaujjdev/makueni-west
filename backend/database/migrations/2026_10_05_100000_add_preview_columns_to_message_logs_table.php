<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a message said and how it went out (docs/specs/settings-spec.md,
 * S6b/S6c): the place it was sent for (territory_id, already there), which
 * account sent it (the diocese's, the place's own, or a system email), its
 * kind, and a copy of the text with any secret masked - for the preview.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_logs', function (Blueprint $table) {
            $table->string('kind', 40)->nullable()->after('channel');
            $table->string('via', 10)->nullable()->after('kind');
            $table->string('from')->nullable()->after('to');
            $table->string('reply_to')->nullable()->after('from');
            $table->mediumText('body')->nullable()->after('subject');
            $table->string('body_type', 8)->nullable()->after('body');
            $table->timestamp('body_cleared_at')->nullable()->after('body_type');
            $table->json('meta')->nullable()->after('sent_by');
            $table->index(['territory_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('message_logs', function (Blueprint $table) {
            $table->dropIndex(['territory_id', 'created_at']);
            $table->dropColumn(['kind', 'via', 'from', 'reply_to', 'body', 'body_type', 'body_cleared_at', 'meta']);
        });
    }
};

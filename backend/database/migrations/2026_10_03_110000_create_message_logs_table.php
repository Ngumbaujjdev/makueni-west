<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every email and SMS the system sends (or only logs, when no gateway is
 * set up) - Settings > System health shows the last one of each, and the
 * "Send a test" buttons write here too (docs/specs/settings-spec.md, S4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_logs', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 10); // mail | sms
            $table->string('to', 255);
            $table->string('subject', 255)->nullable();
            $table->string('status', 10); // sent | failed | logged
            $table->string('provider_ref', 255)->nullable();
            $table->text('error')->nullable();
            $table->foreignId('territory_id')->nullable()->constrained('territories')->nullOnDelete();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['channel', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_logs');
    }
};

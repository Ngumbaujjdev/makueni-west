<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The phone app sends a counting form again when a reply is lost (offline
 * outbox, docs/specs/mobile-app-spec.md, M11). Each send carries the same
 * Idempotency-Key; the first answer is kept here and replayed, so the count
 * is recorded once. Kept 7 days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('method', 10);
            $table->string('route', 191);
            $table->unsignedSmallInteger('status_code');
            $table->longText('response_body');
            $table->timestamp('created_at')->useCurrent()->index();
            $table->unique(['key', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per generated report (PDF or Excel) - see docs/specs/reports-spec.md.
 * The row outlives its file: files are pruned after 7 days, but the
 * verification code and fingerprint stay so a printed copy can still be
 * checked on the Verify page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('territory_id')->index();
            $table->string('report_key', 64);
            $table->string('format', 8);
            $table->json('params')->nullable();
            $table->string('status', 16)->default('queued')->index();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('stage', 120)->nullable();
            $table->string('title')->nullable();
            $table->string('period_label')->nullable();
            $table->string('scope_label')->nullable();
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('verification_code', 32)->nullable()->unique();
            $table->string('file_hash', 64)->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_runs');
    }
};

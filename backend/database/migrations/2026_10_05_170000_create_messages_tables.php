<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Messages (docs/specs/messages-spec.md): a sent message, who it went to, replies, and saved messages. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('territory_id')->constrained('territories')->cascadeOnDelete();
            $table->string('channel', 10);
            $table->string('subject', 120)->nullable();
            $table->text('body');
            $table->json('audience');
            $table->string('summary', 255)->nullable();
            $table->unsignedInteger('recipient_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->string('status', 12);
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['territory_id', 'created_at']);
            $table->index(['status', 'scheduled_at']);
        });

        Schema::create('message_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_batch_id')->constrained('message_batches')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 160)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 191)->nullable();
            $table->foreignId('place_id')->nullable()->constrained('territories')->nullOnDelete();
            $table->string('role', 100)->nullable();
            $table->string('sms_status', 10)->nullable();
            $table->string('email_status', 10)->nullable();
            $table->string('error', 255)->nullable();
            $table->json('log_ids')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('message_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_batch_id')->constrained('message_batches')->cascadeOnDelete();
            $table->foreignId('message_recipient_id')->nullable()->constrained('message_recipients')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('territory_id')->nullable()->constrained('territories')->nullOnDelete();
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('territory_id')->constrained('territories')->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('channel', 10)->default('sms');
            $table->string('subject', 120)->nullable();
            $table->text('body');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_templates');
        Schema::dropIfExists('message_replies');
        Schema::dropIfExists('message_recipients');
        Schema::dropIfExists('message_batches');
    }
};

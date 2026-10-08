<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chat (docs/specs/messages-spec.md, L6): one-to-one chats and groups
 * between the diocese's leaders, next to the one-way announcements.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chats', function (Blueprint $table) {
            $table->id();
            $table->string('type', 10); // direct | group
            $table->string('name', 80)->nullable();
            $table->string('photo_path')->nullable();
            $table->string('direct_key', 40)->nullable()->unique(); // "small:big" user ids - one chat per pair
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('chat_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_id')->constrained('chats')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('is_admin')->default(false);
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->unsignedBigInteger('last_read_message_id')->nullable();
            $table->timestamps();
            $table->unique(['chat_id', 'user_id']);
            $table->index(['user_id', 'left_at']);
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_id')->constrained('chats')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 10)->default('text'); // text | system
            $table->text('body');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['chat_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_members');
        Schema::dropIfExists('chats');
    }
};

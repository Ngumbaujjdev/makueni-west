<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Events and initiatives (docs/specs/events-initiatives-spec.md): one
 * activities table for both, who it's open to, and the places below that
 * register how many are coming (counts, not people). Attendance and money
 * stay in their own tables, tagged with the activity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->string('kind', 12)->default('event');
            $table->foreignId('territory_id')->constrained('territories');
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->string('type', 30);
            $table->string('audience', 20)->default('everyone');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('venue', 160)->nullable();
            $table->unsignedInteger('capacity')->nullable();
            $table->string('coordinator', 120)->nullable();
            $table->string('speakers', 255)->nullable();
            $table->text('agenda')->nullable();
            $table->string('open_to', 10)->default('own');
            $table->boolean('registration')->default(false);
            $table->date('register_by')->nullable();
            $table->decimal('fee_per_person', 12, 2)->nullable();
            $table->decimal('planned_income', 15, 2)->nullable();
            $table->decimal('planned_spend', 15, 2)->nullable();
            $table->string('status', 12)->default('draft');
            $table->text('report_back')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['territory_id', 'kind', 'starts_at']);
            $table->index(['status', 'starts_at']);
        });

        Schema::create('activity_invitees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained('activities')->cascadeOnDelete();
            $table->foreignId('territory_id')->constrained('territories')->cascadeOnDelete();
            $table->unique(['activity_id', 'territory_id']);
        });

        Schema::create('activity_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained('activities')->cascadeOnDelete();
            $table->foreignId('territory_id')->constrained('territories');
            foreach (['youth', 'adults', 'children', 'leaders'] as $group) {
                $table->unsignedInteger($group)->default(0);
            }
            $table->text('names')->nullable();
            $table->decimal('fee_due', 12, 2)->default(0);
            $table->decimal('fee_paid', 12, 2)->default(0);
            foreach (['youth', 'adults', 'children', 'leaders'] as $group) {
                $table->unsignedInteger("came_{$group}")->nullable();
            }
            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('comment')->nullable();
            $table->string('status', 12)->default('registered');
            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['activity_id', 'territory_id']);
        });

        Schema::table('church_attendance_records', function (Blueprint $table) {
            $table->foreignId('activity_id')->nullable()->after('gathering_type_id')->constrained('activities')->nullOnDelete();
        });
        Schema::table('budget_entries', function (Blueprint $table) {
            $table->foreignId('activity_id')->nullable()->after('budget_line_item_id')->constrained('activities')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('budget_entries', fn (Blueprint $table) => $table->dropConstrainedForeignId('activity_id'));
        Schema::table('church_attendance_records', fn (Blueprint $table) => $table->dropConstrainedForeignId('activity_id'));
        Schema::dropIfExists('activity_registrations');
        Schema::dropIfExists('activity_invitees');
        Schema::dropIfExists('activities');
    }
};

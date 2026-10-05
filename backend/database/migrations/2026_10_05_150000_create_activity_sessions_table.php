<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Initiatives (docs/specs/events-initiatives-spec.md, L2): how often an
 * initiative meets, its sessions with attendance, and how many from each
 * place finished.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->string('frequency', 20)->nullable()->after('agenda');
            $table->unsignedTinyInteger('meeting_day')->nullable()->after('frequency');
            $table->time('meeting_time')->nullable()->after('meeting_day');
            $table->boolean('certificate')->default(false)->after('meeting_time');
        });

        Schema::create('activity_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained('activities')->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->date('held_on');
            $table->string('topic', 160)->nullable();
            $table->string('status', 20)->default('planned');
            foreach (['youth', 'adults', 'children', 'leaders'] as $group) {
                $table->unsignedInteger($group)->nullable();
            }
            $table->text('notes')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['activity_id', 'held_on']);
        });

        Schema::table('activity_registrations', function (Blueprint $table) {
            $table->unsignedInteger('completed')->nullable()->after('came_leaders');
        });
    }

    public function down(): void
    {
        Schema::table('activity_registrations', fn (Blueprint $table) => $table->dropColumn('completed'));
        Schema::dropIfExists('activity_sessions');
        Schema::table('activities', fn (Blueprint $table) => $table->dropColumn(['frequency', 'meeting_day', 'meeting_time', 'certificate']));
    }
};

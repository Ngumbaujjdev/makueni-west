<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The calendar (docs/specs/calendar-spec.md, C1): one row per event, owned
 * by one territory. The national territory (territory_type = global) owns
 * the CCI calendar. Repeats are expanded when read, not stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uid')->unique();
            $table->foreignId('territory_id')->constrained('territories');
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->string('kind', 30)->default('other');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->boolean('all_day')->default(true);
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->string('location', 160)->nullable();
            $table->string('repeats', 10)->default('none');
            $table->date('repeat_until')->nullable();
            $table->boolean('shared_below')->default(true);
            $table->string('source', 10)->default('manual');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['territory_id', 'starts_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
    }
};

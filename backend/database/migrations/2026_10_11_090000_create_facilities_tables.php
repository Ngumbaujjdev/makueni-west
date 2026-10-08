<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * People & care, P5 (docs/specs/people-and-care-spec.md): a church's
 * facilities - its rooms and who has them when, its equipment and who has
 * borrowed it, the repairs, and who is on duty at each service. All of it
 * is the church's own; times are Nairobi wall-clock times.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('territory_id')->constrained('territories')->cascadeOnDelete();
            $table->string('name', 80);
            $table->unsignedInteger('capacity')->nullable();
            $table->boolean('bookable')->default(true);
            $table->string('colour', 12)->nullable();
            $table->string('notes', 500)->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['territory_id', 'active']);
        });

        Schema::create('room_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('territory_id')->constrained('territories')->cascadeOnDelete();
            $table->foreignId('room_id')->constrained('rooms')->cascadeOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('purpose', 160);
            $table->foreignId('booked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ministry_id')->nullable()->constrained('ministries')->nullOnDelete();
            $table->foreignId('activity_id')->nullable()->constrained('activities')->nullOnDelete();
            $table->string('repeat', 8)->default('none');
            $table->date('repeat_until')->nullable();
            $table->string('status', 10)->default('booked');
            $table->timestamps();

            $table->index(['room_id', 'status', 'starts_at']);
            $table->index(['territory_id', 'starts_at']);
        });

        Schema::create('equipment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('territory_id')->constrained('territories')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('category', 20)->default('other');
            $table->foreignId('room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->string('condition', 8)->default('good');
            $table->date('bought_on')->nullable();
            $table->decimal('value', 12, 2)->nullable();
            $table->string('serial', 80)->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['territory_id', 'condition']);
        });

        Schema::create('equipment_loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $table->string('to_name', 120);
            $table->foreignId('to_person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->date('out_on');
            $table->date('due_on')->nullable();
            $table->date('returned_on')->nullable();
            $table->string('note', 300)->nullable();
            $table->foreignId('by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['equipment_id', 'returned_on']);
        });

        Schema::create('maintenance_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('territory_id')->constrained('territories')->cascadeOnDelete();
            $table->foreignId('equipment_id')->nullable()->constrained('equipment')->nullOnDelete();
            $table->foreignId('room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->string('title', 160);
            $table->string('detail', 1000)->nullable();
            $table->string('priority', 8)->default('normal');
            $table->string('status', 12)->default('reported');
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('cost', 12, 2)->nullable();
            $table->date('done_on')->nullable();
            $table->foreignId('budget_entry_id')->nullable()->constrained('budget_entries')->nullOnDelete();
            $table->timestamps();

            $table->index(['territory_id', 'status']);
        });

        Schema::create('duty_rota', function (Blueprint $table) {
            $table->id();
            $table->foreignId('territory_id')->constrained('territories')->cascadeOnDelete();
            $table->date('on');
            $table->string('service', 60);
            $table->string('duty', 12);
            $table->foreignId('person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->string('name', 120)->nullable();
            $table->timestamps();

            $table->index(['territory_id', 'on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('duty_rota');
        Schema::dropIfExists('maintenance_jobs');
        Schema::dropIfExists('equipment_loans');
        Schema::dropIfExists('equipment');
        Schema::dropIfExists('room_bookings');
        Schema::dropIfExists('rooms');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * People & care, P3 (docs/specs/people-and-care-spec.md): pastoral care - a
 * visit, a call, counselling, someone in hospital, a prayer, a loss, a
 * concern - who went, and each contact after. Notes are encrypted; the
 * church keeps only what it needs (a name, the hospital, a short note).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('care_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('territory_id')->constrained('territories')->cascadeOnDelete();
            $table->foreignId('person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->string('person_name', 120)->nullable();
            $table->string('type', 16);
            $table->string('priority', 8)->default('normal');
            $table->string('status', 10)->default('open');
            $table->date('on');
            $table->string('hospital', 120)->nullable();
            $table->date('discharged_on')->nullable();
            $table->text('note')->nullable();
            $table->boolean('confidential')->default(false);
            $table->date('next_on')->nullable();
            $table->string('testimony', 1000)->nullable();
            $table->boolean('share_testimony')->default(false);
            $table->date('closed_on')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['territory_id', 'status']);
            $table->index(['territory_id', 'type', 'on']);
            $table->index(['territory_id', 'next_on']);
        });

        Schema::create('care_record_users', function (Blueprint $table) {
            $table->foreignId('care_record_id')->constrained('care_records')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->primary(['care_record_id', 'user_id']);
        });

        Schema::create('care_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('care_record_id')->constrained('care_records')->cascadeOnDelete();
            $table->foreignId('territory_id')->constrained('territories')->cascadeOnDelete();
            $table->date('on');
            $table->string('type', 10);
            $table->text('note')->nullable();
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('next_on')->nullable();
            $table->timestamps();

            $table->index(['care_record_id', 'on']);
            $table->index(['territory_id', 'on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('care_contacts');
        Schema::dropIfExists('care_record_users');
        Schema::dropIfExists('care_records');
    }
};

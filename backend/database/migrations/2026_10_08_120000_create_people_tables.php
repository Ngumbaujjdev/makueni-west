<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * People & care, P1 (docs/specs/people-and-care-spec.md): a church's private
 * member register, and the transfers in and out of it. Names stay with the
 * church; ID, address, notes and next of kin's phone are encrypted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('people', function (Blueprint $table) {
            $table->id();
            $table->foreignId('territory_id')->constrained('territories')->cascadeOnDelete();
            $table->string('first_name', 80);
            $table->string('last_name', 80);
            $table->string('other_names', 80)->nullable();
            $table->string('gender', 10)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email', 160)->nullable();
            $table->text('address')->nullable();
            $table->text('national_id')->nullable();
            $table->string('marital_status', 12)->nullable();
            $table->string('occupation', 120)->nullable();
            $table->string('photo_path')->nullable();
            $table->string('status', 20)->default('member');
            $table->date('joined_on')->nullable();
            $table->string('how_joined', 20)->nullable();
            $table->string('previous_church', 160)->nullable();
            $table->date('saved_on')->nullable();
            $table->date('baptised_on')->nullable();
            $table->string('next_of_kin_name', 120)->nullable();
            $table->text('next_of_kin_phone')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('anonymised_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['territory_id', 'status']);
            $table->index(['territory_id', 'phone']);
            $table->index(['territory_id', 'last_name', 'first_name']);
        });

        Schema::create('person_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->foreignId('territory_id')->constrained('territories')->cascadeOnDelete();
            $table->string('direction', 3);
            $table->foreignId('other_church_id')->nullable()->constrained('territories')->nullOnDelete();
            $table->string('other_church_name', 160)->nullable();
            $table->date('on');
            $table->text('reason')->nullable();
            $table->boolean('notified')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['territory_id', 'direction', 'on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_transfers');
        Schema::dropIfExists('people');
    }
};

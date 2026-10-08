<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * People & care, P4 (docs/specs/people-and-care-spec.md): a church's
 * ministries - youth, women, men, children, music, prayer and its own -
 * who leads each, who serves in it, and the gathering it meets as. Members
 * are people already in the register; nothing new is kept about them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ministries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('territory_id')->constrained('territories')->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('kind', 12);
            // The standard six carry their kind here, once per church - so two first reads at once can't make them twice.
            $table->string('standard', 12)->nullable();
            $table->string('icon', 40)->nullable();
            $table->string('colour', 12)->nullable();
            $table->unsignedTinyInteger('meets_day')->nullable();
            $table->time('meets_time')->nullable();
            $table->foreignId('gathering_type_id')->nullable()->constrained('gathering_types')->nullOnDelete();
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['territory_id', 'standard']);
            $table->index(['territory_id', 'active']);
        });

        Schema::create('ministry_leaders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ministry_id')->constrained('ministries')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('person_id')->nullable()->constrained('people')->cascadeOnDelete();
            $table->string('role', 12)->default('leader');
            $table->timestamps();

            $table->index(['user_id']);
        });

        Schema::create('ministry_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ministry_id')->constrained('ministries')->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->date('joined_on')->nullable();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['ministry_id', 'person_id']);
            $table->index(['person_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ministry_members');
        Schema::dropIfExists('ministry_leaders');
        Schema::dropIfExists('ministries');
    }
};

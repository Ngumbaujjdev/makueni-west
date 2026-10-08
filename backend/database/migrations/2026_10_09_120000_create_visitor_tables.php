<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * People & care, P2 (docs/specs/people-and-care-spec.md): visitors are people
 * with status "visitor" - their visits and the follow-up after them. Prayer
 * requests and follow-up notes are encrypted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->date('first_visit_on')->nullable()->after('baptised_on');
            $table->date('last_visit_on')->nullable()->after('first_visit_on');
            $table->unsignedInteger('visit_count')->default(0)->after('last_visit_on');
            $table->string('how_heard', 60)->nullable()->after('visit_count');
            $table->boolean('consent_contact')->default(false)->after('how_heard');
            $table->boolean('wants_visit')->default(false)->after('consent_contact');
            $table->string('stage', 12)->nullable()->after('wants_visit');
            $table->foreignId('assigned_to')->nullable()->after('stage')->constrained('users')->nullOnDelete();
            $table->date('became_member_on')->nullable()->after('assigned_to');

            $table->index(['territory_id', 'stage']);
        });

        Schema::create('visitor_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->foreignId('territory_id')->constrained('territories')->cascadeOnDelete();
            $table->foreignId('gathering_type_id')->nullable()->constrained('gathering_types')->nullOnDelete();
            $table->date('on');
            $table->boolean('first_time')->default(false);
            $table->boolean('wants_visit')->default(false);
            $table->text('prayer_request')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['territory_id', 'on']);
            $table->index(['person_id', 'on']);
        });

        Schema::create('visitor_followups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->foreignId('territory_id')->constrained('territories')->cascadeOnDelete();
            $table->string('type', 10);
            $table->string('outcome', 16);
            $table->text('note')->nullable();
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('done_on');
            $table->date('next_on')->nullable();
            $table->timestamps();

            $table->index(['territory_id', 'done_on']);
            $table->index(['person_id', 'done_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitor_followups');
        Schema::dropIfExists('visitor_visits');
        Schema::table('people', function (Blueprint $table) {
            $table->dropIndex(['territory_id', 'stage']);
            $table->dropConstrainedForeignId('assigned_to');
            $table->dropColumn(['first_visit_on', 'last_visit_on', 'visit_count', 'how_heard', 'consent_contact', 'wants_visit', 'stage', 'became_member_on']);
        });
    }
};

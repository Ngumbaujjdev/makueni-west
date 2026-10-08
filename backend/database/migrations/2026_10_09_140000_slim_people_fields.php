<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * People & care, slimmed down (2026-10-09, docs/specs/people-and-care-spec.md):
 * churches record a name, phone, area and gender - and for members whether
 * they are in Sunday school or the main church. Everything else the first
 * build asked for (date of birth, ID, address, photo, next of kin...) is no
 * longer collected, so it is not kept either.
 */
return new class extends Migration
{
    private const DROPPED = [
        'date_of_birth', 'address', 'national_id', 'marital_status', 'occupation', 'other_names', 'email', 'photo_path',
        'next_of_kin_name', 'next_of_kin_phone', 'notes', 'how_heard', 'wants_visit',
    ];

    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->string('area', 80)->nullable()->after('phone');
            $table->string('congregation', 16)->nullable()->after('area');
            $table->index(['territory_id', 'area']);
        });
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn(self::DROPPED);
        });
        Schema::table('visitor_visits', function (Blueprint $table) {
            $table->dropColumn(['prayer_request', 'wants_visit']);
        });
    }

    public function down(): void
    {
        Schema::table('visitor_visits', function (Blueprint $table) {
            $table->boolean('wants_visit')->default(false)->after('first_time');
            $table->text('prayer_request')->nullable()->after('wants_visit');
        });
        Schema::table('people', function (Blueprint $table) {
            $table->dropIndex(['territory_id', 'area']);
            $table->dropColumn(['area', 'congregation']);
            $table->string('other_names', 80)->nullable()->after('last_name');
            $table->date('date_of_birth')->nullable()->after('gender');
            $table->string('email', 160)->nullable()->after('phone');
            $table->text('address')->nullable()->after('email');
            $table->text('national_id')->nullable()->after('address');
            $table->string('marital_status', 12)->nullable()->after('national_id');
            $table->string('occupation', 120)->nullable()->after('marital_status');
            $table->string('photo_path')->nullable()->after('occupation');
            $table->string('next_of_kin_name', 120)->nullable()->after('baptised_on');
            $table->text('next_of_kin_phone')->nullable()->after('next_of_kin_name');
            $table->text('notes')->nullable()->after('next_of_kin_phone');
            $table->string('how_heard', 60)->nullable()->after('visit_count');
            $table->boolean('wants_visit')->default(false)->after('consent_contact');
        });
    }
};

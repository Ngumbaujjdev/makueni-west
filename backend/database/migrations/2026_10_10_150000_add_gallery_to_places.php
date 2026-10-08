<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings > Profile, online (docs/specs/settings-spec.md): a place's
 * YouTube link (services online) and its photo gallery - the start of each
 * church's own page. Photos are kept as WebP by the image engine; the
 * original upload is never stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('territories', function (Blueprint $table) {
            $table->string('youtube_url')->nullable()->after('website');
        });

        Schema::create('place_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('territory_id')->constrained('territories')->cascadeOnDelete();
            $table->string('path');
            $table->string('thumb_path')->nullable();
            $table->string('caption', 160)->nullable();
            $table->unsignedInteger('width')->default(0);
            $table->unsignedInteger('height')->default(0);
            $table->unsignedInteger('bytes')->default(0);
            $table->unsignedInteger('position')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['territory_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('place_photos');
        Schema::table('territories', function (Blueprint $table) {
            $table->dropColumn('youtube_url');
        });
    }
};

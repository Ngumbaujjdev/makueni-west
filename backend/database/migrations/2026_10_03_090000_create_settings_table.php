<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings that flow down the territory chain (docs/specs/settings-spec.md):
 * a row exists only while a place's value differs from what it inherits.
 * territory_id NULL is the system level; scope_id makes the unique index
 * treat that as one place (MySQL lets duplicate NULLs through a unique key).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            // No ON DELETE CASCADE: MySQL refuses it on a base column of a stored
            // generated column (scope_id). Territories are soft-deleted anyway.
            $table->foreignId('territory_id')->nullable()->constrained('territories');
            $table->unsignedBigInteger('scope_id')->storedAs('IFNULL(territory_id, 0)');
            $table->string('key', 120);
            $table->longText('value')->nullable();
            $table->boolean('is_locked')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['scope_id', 'key']);
            $table->index('key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};

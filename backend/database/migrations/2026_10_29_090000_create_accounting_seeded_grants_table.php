<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The default grants AccountingAccessSeeder has made (role, permission), so a
 * later run never puts back one the diocese admin took away in Roles &
 * permissions - who may record is the admin's to decide.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_seeded_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['role_id', 'permission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_seeded_grants');
    }
};

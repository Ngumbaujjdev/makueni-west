<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Multi-auth identifiers
            $table->string('phone', 20)->nullable()->after('email');
            $table->string('employee_code', 6)->nullable()->after('phone');

            // PIN authentication fields
            $table->string('pin', 60)->nullable()->after('password');
            $table->timestamp('pin_changed_at')->nullable()->after('pin');
            $table->tinyInteger('failed_pin_attempts')->default(0)->after('pin_changed_at');
            $table->timestamp('pin_locked_until')->nullable()->after('failed_pin_attempts');

            // Account status and security
            $table->enum('status', ['active', 'inactive', 'suspended'])->default('active')->after('pin_locked_until');
            $table->integer('login_attempts')->default(0)->after('status');
            $table->timestamp('last_login_at')->nullable()->after('login_attempts');
            $table->timestamp('password_changed_at')->nullable()->after('last_login_at');
            $table->boolean('must_change_password')->default(false)->after('password_changed_at');
            $table->timestamp('password_expires_at')->nullable()->after('must_change_password');

            // Additional user info
            $table->string('firstname')->nullable()->after('name');
            $table->string('lastname')->nullable()->after('firstname');
            $table->string('username', 50)->nullable()->after('lastname');
        });

        // Add unique indexes separately to avoid issues
        Schema::table('users', function (Blueprint $table) {
            $table->unique(['phone'], 'users_phone_unique');
            $table->unique(['employee_code'], 'users_employee_code_unique');
            $table->unique(['username'], 'users_username_unique');

            // Regular indexes
            $table->index(['email'], 'users_email_index');
            $table->index(['status'], 'users_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Drop indexes first
            $table->dropUnique('users_phone_unique');
            $table->dropUnique('users_employee_code_unique');
            $table->dropUnique('users_username_unique');
            $table->dropIndex('users_email_index');
            $table->dropIndex('users_status_index');

            // Then drop columns
            $table->dropColumn([
                'phone',
                'employee_code',
                'pin',
                'pin_changed_at',
                'failed_pin_attempts',
                'pin_locked_until',
                'status',
                'login_attempts',
                'last_login_at',
                'password_changed_at',
                'must_change_password',
                'password_expires_at',
                'firstname',
                'lastname',
                'username',
            ]);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Check and add columns that might be missing
            if (!Schema::hasColumn('users', 'firstname')) {
                $table->string('firstname')->nullable()->after('name');
            }

            if (!Schema::hasColumn('users', 'lastname')) {
                $table->string('lastname')->nullable()->after('firstname');
            }

            if (!Schema::hasColumn('users', 'username')) {
                $table->string('username')->unique()->nullable()->after('lastname');
            }

            if (!Schema::hasColumn('users', 'phone')) {
                $table->string('phone')->unique()->nullable()->after('email');
            }

            if (!Schema::hasColumn('users', 'position')) {
                $table->string('position')->nullable()->after('phone');
            }

            if (!Schema::hasColumn('users', 'employee_code')) {
                $table->string('employee_code', 6)->unique()->nullable()->after('position');
            }

            if (!Schema::hasColumn('users', 'pin')) {
                $table->string('pin')->nullable()->after('password');
            }

            if (!Schema::hasColumn('users', 'pin_changed_at')) {
                $table->timestamp('pin_changed_at')->nullable()->after('pin');
            }

            if (!Schema::hasColumn('users', 'failed_pin_attempts')) {
                $table->integer('failed_pin_attempts')->default(0)->after('pin_changed_at');
            }

            if (!Schema::hasColumn('users', 'pin_locked_until')) {
                $table->timestamp('pin_locked_until')->nullable()->after('failed_pin_attempts');
            }

            if (!Schema::hasColumn('users', 'status')) {
                $table->enum('status', ['active', 'inactive', 'suspended'])->default('active')->after('pin_locked_until');
            }

            if (!Schema::hasColumn('users', 'login_attempts')) {
                $table->integer('login_attempts')->default(0)->after('status');
            }

            if (!Schema::hasColumn('users', 'last_login_at')) {
                $table->timestamp('last_login_at')->nullable()->after('login_attempts');
            }

            if (!Schema::hasColumn('users', 'password_changed_at')) {
                $table->timestamp('password_changed_at')->nullable()->after('last_login_at');
            }

            if (!Schema::hasColumn('users', 'must_change_password')) {
                $table->boolean('must_change_password')->default(false)->after('password_changed_at');
            }

            if (!Schema::hasColumn('users', 'password_expires_at')) {
                $table->timestamp('password_expires_at')->nullable()->after('must_change_password');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $columnsToRemove = [
                'firstname', 'lastname', 'username', 'phone', 'position',
                'employee_code', 'pin', 'pin_changed_at', 'failed_pin_attempts',
                'pin_locked_until', 'status', 'login_attempts', 'last_login_at',
                'password_changed_at', 'must_change_password', 'password_expires_at'
            ];

            foreach ($columnsToRemove as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

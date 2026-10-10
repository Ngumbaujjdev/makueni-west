<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Staff (docs/specs/hr-spec.md): positions, grades and allowance types that
 * each level sets up (the diocese's are everyone's defaults), the defaults a
 * place has switched off, and the people a place employs - the payroll's
 * employees, extended - with where they have been posted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_grades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('territory_id')->constrained('territories')->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name', 100);
            $table->decimal('min_pay', 15, 2)->nullable();
            $table->decimal('max_pay', 15, 2)->nullable();
            $table->decimal('default_pay', 15, 2)->nullable();
            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('display_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['territory_id', 'code']);
        });

        Schema::create('hr_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('territory_id')->constrained('territories')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            $table->json('levels')->nullable();
            $table->foreignId('grade_id')->nullable()->constrained('hr_grades')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('display_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['territory_id', 'name']);
        });

        Schema::create('hr_allowance_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('territory_id')->constrained('territories')->cascadeOnDelete();
            $table->string('name', 60);
            $table->decimal('default_amount', 15, 2)->nullable();
            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('display_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['territory_id', 'name']);
        });

        Schema::create('hr_hidden', function (Blueprint $table) {
            $table->id();
            $table->foreignId('territory_id')->constrained('territories')->cascadeOnDelete();
            $table->enum('kind', ['position', 'grade', 'allowance']);
            $table->unsignedBigInteger('item_id');
            $table->timestamps();
            $table->unique(['territory_id', 'kind', 'item_id']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('person_id')->nullable()->after('user_id')->constrained('people')->nullOnDelete();
            $table->foreignId('position_id')->nullable()->after('position')->constrained('hr_positions')->nullOnDelete();
            $table->foreignId('grade_id')->nullable()->after('position_id')->constrained('hr_grades')->nullOnDelete();
            $table->string('employment_type', 20)->default('full_time')->after('grade_id');
            $table->date('contract_end')->nullable()->after('end_date');
        });

        Schema::create('staff_postings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('territory_id')->constrained('territories')->cascadeOnDelete();
            $table->string('position', 100)->nullable();
            $table->foreignId('position_id')->nullable()->constrained('hr_positions')->nullOnDelete();
            $table->date('from_date');
            $table->date('to_date')->nullable();
            $table->enum('reason', ['hired', 'transferred', 'changed', 'left'])->default('hired');
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['territory_id', 'from_date']);
        });

        // Everyone already on a payroll was hired where they are.
        foreach (DB::table('employees')->get() as $e) {
            DB::table('staff_postings')->insert([
                'employee_id' => $e->id, 'territory_id' => $e->territory_id, 'position' => $e->position,
                'from_date' => $e->start_date ?? substr((string) $e->created_at, 0, 10), 'to_date' => $e->end_date,
                'reason' => 'hired', 'created_by' => $e->created_by, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_postings');
        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('person_id');
            $table->dropConstrainedForeignId('position_id');
            $table->dropConstrainedForeignId('grade_id');
            $table->dropColumn(['employment_type', 'contract_end']);
        });
        Schema::dropIfExists('hr_hidden');
        Schema::dropIfExists('hr_allowance_types');
        Schema::dropIfExists('hr_positions');
        Schema::dropIfExists('hr_grades');
    }
};

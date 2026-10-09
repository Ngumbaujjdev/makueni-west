<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounting A4 (docs/specs/accounting-spec.md): the approvals engine -
 * configurable workflows (who approves what, by level and amount), each
 * request's frozen copy of its stages, the people assigned, their
 * decisions, the event trail and delegations - plus requisitions (asking
 * for money) and staff advances (money given ahead, accounted for later).
 * Ported from erp-server's engine, without its subsidiaries and statuses
 * table: statuses are plain strings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_workflows', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80)->nullable()->index();
            $table->string('name', 150);
            $table->string('subject_type', 40)->index(); // a morph alias, or '*' for any money document
            $table->enum('level', ['church', 'region', 'diocese'])->nullable(); // null = every level
            $table->json('applies_when')->nullable();
            $table->unsignedInteger('match_priority')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('approval_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained('approval_workflows')->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('name', 120);
            $table->enum('type', ['single', 'all', 'quorum'])->default('single');
            $table->unsignedInteger('quorum')->nullable();
            $table->enum('on_reject', ['terminate', 'return_previous', 'continue'])->default('terminate');
            $table->enum('on_empty', ['block', 'skip', 'escalate'])->default('block'); // escalate: nobody left -> straight to the escalation people
            $table->unsignedInteger('sla_hours')->nullable();
            $table->unsignedInteger('escalate_after_hours')->nullable();
            $table->json('escalate_to')->nullable(); // one step: {resolver_type, resolver_config}
            $table->timestamps();
            $table->index(['workflow_id', 'sequence']);
        });

        Schema::create('approval_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stage_id')->constrained('approval_stages')->cascadeOnDelete();
            $table->string('resolver_type', 40);
            $table->json('resolver_config')->nullable();
            $table->timestamps();
        });

        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workflow_id')->nullable()->index();
            $table->unsignedInteger('workflow_version')->default(1);
            $table->string('workflow_name', 150)->nullable();
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('territory_id')->constrained('territories')->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('context')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected', 'returned', 'cancelled'])->default('pending');
            $table->unsignedInteger('current_stage_sequence')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['subject_type', 'subject_id']);
            $table->index(['territory_id', 'status']);
        });

        Schema::create('approval_request_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('approval_requests')->cascadeOnDelete();
            $table->unsignedBigInteger('stage_id')->nullable();
            $table->unsignedInteger('sequence');
            $table->string('name', 120);
            $table->string('type', 20);
            $table->unsignedInteger('quorum')->nullable();
            $table->string('on_reject', 20);
            $table->json('rule_snapshot')->nullable();
            $table->enum('status', ['pending', 'active', 'approved', 'rejected', 'skipped', 'blocked'])->default('pending');
            $table->string('blocked_reason', 255)->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['request_id', 'sequence']);
        });

        Schema::create('approval_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('approval_requests')->cascadeOnDelete();
            $table->foreignId('request_stage_id')->constrained('approval_request_stages')->cascadeOnDelete();
            $table->foreignId('approver_id')->constrained('users')->cascadeOnDelete();
            $table->string('resolver_type', 40)->nullable();
            $table->unsignedBigInteger('delegated_from')->nullable();
            $table->unsignedBigInteger('escalated_from')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected', 'returned', 'skipped', 'superseded'])->default('pending');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();
            $table->index(['request_stage_id', 'status'], 'approval_assign_stage_status');
            $table->index(['approver_id', 'status'], 'approval_assign_approver_status');
            $table->index(['status', 'due_at'], 'approval_assign_status_due');
        });

        Schema::create('approval_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained('approval_assignments')->cascadeOnDelete();
            $table->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $table->enum('decision', ['approve', 'reject', 'return']);
            $table->text('comment')->nullable();
            $table->timestamps();
        });

        Schema::create('approval_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('approval_requests')->cascadeOnDelete();
            $table->string('type', 40);
            $table->json('payload')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['request_id', 'type']);
        });

        Schema::create('approval_delegations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delegator_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('delegate_id')->constrained('users')->cascadeOnDelete();
            $table->string('subject_type', 40)->nullable(); // null = every kind of document
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('reason', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['delegator_id', 'is_active']);
        });

        Schema::create('requisitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('territory_id')->constrained('territories')->cascadeOnDelete();
            $table->string('number', 60);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('kind', ['payment', 'purchase', 'advance'])->default('payment');
            $table->string('purpose', 255);
            $table->decimal('amount', 15, 2);
            $table->date('needed_by')->nullable();
            $table->foreignId('account_id')->nullable()->constrained('accounting_accounts')->nullOnDelete();
            $table->foreignId('budget_line_id')->nullable()->constrained('budget_lines')->nullOnDelete();
            $table->foreignId('fund_id')->nullable()->constrained('accounting_funds')->nullOnDelete();
            $table->string('payee_name', 150)->nullable();
            $table->string('payee_phone', 30)->nullable();
            $table->enum('status', ['submitted', 'approved', 'returned', 'rejected', 'paid', 'cancelled'])->default('submitted');
            $table->string('decision_note', 255)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('payment_voucher_id')->nullable()->constrained('payment_vouchers')->nullOnDelete();
            $table->timestamps();
            $table->unique(['territory_id', 'number']);
            $table->index(['territory_id', 'status']);
        });

        Schema::table('payment_vouchers', function (Blueprint $table) {
            $table->foreignId('requisition_id')->nullable()->after('purpose')->constrained('requisitions')->nullOnDelete();
        });
        Schema::table('payment_vouchers', function (Blueprint $table) {
            $table->enum('purpose', ['payment', 'imprest_topup', 'advance'])->default('payment')->change();
        });

        Schema::create('staff_advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('territory_id')->constrained('territories')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('holder_name', 150);
            $table->foreignId('requisition_id')->nullable()->constrained('requisitions')->nullOnDelete();
            $table->foreignId('payment_voucher_id')->nullable()->constrained('payment_vouchers')->nullOnDelete();
            $table->string('purpose', 255);
            $table->decimal('amount', 15, 2);
            $table->date('issued_on');
            $table->date('due_on');
            $table->decimal('spent', 15, 2)->default(0);
            $table->decimal('returned', 15, 2)->default(0);
            $table->enum('status', ['open', 'retired'])->default('open');
            $table->timestamps();
            $table->index(['territory_id', 'status']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('advance_retirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('advance_id')->constrained('staff_advances')->cascadeOnDelete();
            $table->date('date');
            $table->decimal('spent', 15, 2)->default(0);
            $table->decimal('returned', 15, 2)->default(0);
            $table->foreignId('journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advance_retirements');
        Schema::dropIfExists('staff_advances');
        Schema::table('payment_vouchers', function (Blueprint $table) {
            $table->enum('purpose', ['payment', 'imprest_topup'])->default('payment')->change();
        });
        Schema::table('payment_vouchers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('requisition_id');
        });
        Schema::dropIfExists('requisitions');
        foreach (['approval_delegations', 'approval_events', 'approval_decisions', 'approval_assignments', 'approval_request_stages', 'approval_requests', 'approval_steps', 'approval_stages', 'approval_workflows'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};

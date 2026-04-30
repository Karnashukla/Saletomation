<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operational visibility tables.
 *
 * agent_runs: every cron invocation of the daily agent gets a row.
 *   - Lets you see "Did the agent run today?" without SSH-ing into cPanel
 *   - llm_tokens_used is critical for cost tracking — lets us alert if
 *     a single run starts using 10x normal tokens (= bug or runaway prompt)
 *   - log_path points to the per-run log file in storage/agent_logs/
 *
 * audit_log: any sensitive action gets logged here.
 *   - User changes role, sends email, deletes connection, exports CSV, etc.
 *   - This is what your future self thanks you for when something goes wrong
 *     and you need to know "who did what when?"
 *   - We do NOT use this for routine reads — only for state-changing actions
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->enum('status', ['running', 'completed', 'failed', 'killed'])->default('running');
            $table->unsignedInteger('companies_evaluated')->default(0);
            $table->unsignedInteger('companies_added')->default(0);
            $table->unsignedInteger('signals_detected')->default(0);
            $table->unsignedInteger('prospects_added')->default(0);
            $table->unsignedInteger('drafts_generated')->default(0);
            $table->unsignedInteger('llm_tokens_used')->default(0);
            $table->string('log_path', 500)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'started_at']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete()->comment('NULL = system action');
            $table->string('action', 100)->comment('email_sent, draft_approved, role_changed, csv_imported, etc.');
            $table->string('subject_type', 100)->nullable()->comment('Polymorphic: prospect, draft, user, csv_import');
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('changes')->nullable()->comment('Before/after state for diffable changes');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'user_id', 'created_at']);
            $table->index(['tenant_id', 'action']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('agent_runs');
    }
};

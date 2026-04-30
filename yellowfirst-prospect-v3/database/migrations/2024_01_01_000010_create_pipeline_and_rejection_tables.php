<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM pipeline + the rejection taxonomy.
 *
 * pipeline_stages: tenant-configurable
 *   Defaults seeded by PipelineStageSeeder:
 *     Prospecting → Qualification → Proposal Sent → Negotiation → Closed Won / Closed Lost
 *
 * pipeline_entries: the actual deals
 *   - One entry per deal (a prospect can have multiple deals over time)
 *   - expected_close_date is the "push to Q3" feature from the prototype
 *   - stage_changed_at lets us track how long deals sit in each stage
 *
 * rejection_reasons:
 *   - Tenant-scoped, but tenant_id NULL = global default (seeded for all tenants)
 *   - affects_future_research: if true, the agent uses this rejection to learn
 *     (e.g. "wrong company" should suppress similar companies; "bad timing"
 *     should re-surface in 3 months)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipeline_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->string('code', 50);
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->boolean('is_terminal')->default(false);
            $table->enum('terminal_outcome', ['won', 'lost'])->nullable();
            $table->string('color', 20)->nullable()->comment('Hex color for UI');
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'sort_order']);
        });

        Schema::create('pipeline_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('prospect_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stage_id')->constrained('pipeline_stages')->restrictOnDelete();
            $table->foreignId('business_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->decimal('value_usd', 12, 2)->nullable();
            $table->date('expected_close_date')->nullable();
            $table->timestamp('stage_changed_at')->nullable();
            $table->timestamp('won_lost_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'stage_id']);
            $table->index(['tenant_id', 'owner_user_id']);
            $table->index(['tenant_id', 'expected_close_date']);
        });

        Schema::create('rejection_reasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete()->comment('NULL = global default');
            $table->string('code', 50);
            $table->string('label');
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->boolean('affects_future_research')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        // Add the FK from drafts.rejection_reason_id now that the table exists.
        Schema::table('drafts', function (Blueprint $table) {
            $table->foreign('rejection_reason_id')->references('id')->on('rejection_reasons')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('drafts', function (Blueprint $table) {
            $table->dropForeign(['rejection_reason_id']);
        });
        Schema::dropIfExists('rejection_reasons');
        Schema::dropIfExists('pipeline_entries');
        Schema::dropIfExists('pipeline_stages');
    }
};

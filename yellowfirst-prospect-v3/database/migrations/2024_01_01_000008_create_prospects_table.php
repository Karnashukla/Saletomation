<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prospects — the actual humans we email.
 *
 * Distinct from connections (which come from a CSV) — a prospect is
 * someone the *agent* identified as worth emailing at a high-signal company.
 *
 * email_pattern_source tracks how we got the email:
 *   public_page    — found on company website / press release / LinkedIn
 *   llm_inferred   — pattern detected from at least 2 known examples
 *   csv_provided   — verified email came from imported CSV
 *   manually_entered — user typed it in
 *
 * connection_id (and connection_owner_user_id) are the warm-intro link.
 * If a prospect happens to also be a connection in someone's CSV, we
 * record that — the dashboard can then surface "Reez can intro you here."
 *
 * Status flows: new → draft_ready → sent → replied → won / lost
 *                                   ↓
 *                                rejected
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prospects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('title');
            $table->string('function', 50)->nullable()->comment('engineering, product, data, design, executive');
            $table->enum('seniority', ['c_level', 'vp', 'director', 'head_of', 'other'])->nullable();
            $table->string('linkedin_url', 500)->nullable();
            $table->string('email')->nullable();
            $table->enum('email_pattern_source', ['public_page', 'llm_inferred', 'csv_provided', 'manually_entered'])->nullable();
            $table->unsignedTinyInteger('email_confidence')->default(0)->comment('0-100');
            $table->foreignId('connection_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('connection_owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', [
                'new',
                'draft_ready',
                'sent',
                'replied',
                'rejected',
                'won',
                'lost'
            ])->default('new');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'company_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'assigned_to_user_id']);
            $table->index(['tenant_id', 'connection_owner_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prospects');
    }
};

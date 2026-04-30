<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Signals detected — append-only log.
 *
 * Every time a signal fires for a company, we write a row here. We never
 * update or delete these — they're the audit trail of "why did the agent
 * surface this company?"
 *
 * weight_at_detection is critical:
 *   When you tune signal weights in docs/01-signals.md (e.g. bumping
 *   funding_round from 10 to 12), already-detected signals KEEP their
 *   old weight. This means historical scores stay stable. The dashboard
 *   can show "if rescored today" as a separate read against current weights.
 *
 * evidence is JSON because every signal has different fields:
 *   funding_round → { url, amount_usd, round_type, lead_investor, source }
 *   hiring_surge  → { roles: [...], total_count, source_url }
 *   leadership_change → { person, from_company, to_company, role, source_url }
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signals_detected', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('signal_id', 50)->comment('Matches id in docs/01-signals.md');
            $table->unsignedTinyInteger('weight_at_detection')->comment('Snapshot of signal weight at detection time');
            $table->enum('strength', ['low', 'medium', 'high', 'critical'])->default('medium');
            $table->json('evidence')->comment('URL, headline, date, snippet, etc.');
            $table->timestamp('detected_at');
            $table->date('event_date')->nullable()->comment('When the event itself happened');
            $table->timestamps();

            $table->index(['tenant_id', 'company_id']);
            $table->index(['tenant_id', 'signal_id', 'detected_at']);
            $table->index(['tenant_id', 'detected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signals_detected');
    }
};

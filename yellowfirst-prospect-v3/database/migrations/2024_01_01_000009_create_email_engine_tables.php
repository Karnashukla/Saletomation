<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The email engine: drafts → scheduled_sends → email_threads
 *
 * drafts: AI-generated email content
 *   - sequence_position 0 is the initial email
 *   - 1, 2, 3 are the day 3 / 5 / 7 follow-ups (pre-staged when initial is approved)
 *   - signal_evidence_used is JSON listing which signals informed the body
 *
 * scheduled_sends: the outbound queue
 *   - The 15-minute cron picks rows where send_at <= NOW() AND status = 'pending'
 *   - Warm-up cadence enforced here: don't send more than N per day per sender
 *
 * email_threads: tracks what was sent and listens for replies
 *   - The 30-minute cron polls IMAP/Gmail API and updates reply_received
 *   - When reply_received flips true, all pending follow-ups for that prospect
 *     are cancelled (the trigger lives in app/Services/EmailReplyHandler.php)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('prospect_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('sequence_position')->default(0)->comment('0=initial, 1=D3, 2=D5, 3=D7');
            $table->string('subject');
            $table->longText('body_text');
            $table->longText('body_html')->nullable();
            $table->json('signal_evidence_used')->nullable()->comment('Which signals informed this draft');
            $table->enum('status', ['pending_approval', 'approved', 'sent', 'rejected', 'cancelled'])->default('pending_approval');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejection_reason_id')->nullable();
            $table->text('rejection_notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'prospect_id', 'sequence_position']);
        });

        Schema::create('scheduled_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('draft_id')->constrained()->cascadeOnDelete();
            $table->timestamp('send_at');
            $table->enum('status', ['pending', 'sending', 'sent', 'failed', 'cancelled'])->default('pending');
            $table->timestamp('sent_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->string('smtp_message_id')->nullable()->comment('For threading replies');
            $table->timestamps();

            $table->index(['send_at', 'status'])->comment('Hot index for the 15-minute cron');
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('email_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('prospect_id')->constrained()->cascadeOnDelete();
            $table->string('thread_external_id')->comment('Gmail/Outlook thread ID');
            $table->timestamp('last_message_at')->nullable();
            $table->boolean('reply_received')->default(false);
            $table->timestamp('reply_received_at')->nullable();
            $table->text('reply_summary')->nullable()->comment('LLM-summarized: positive/neutral/negative/unsubscribe');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'reply_received']);
            $table->index(['tenant_id', 'prospect_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_threads');
        Schema::dropIfExists('scheduled_sends');
        Schema::dropIfExists('drafts');
    }
};

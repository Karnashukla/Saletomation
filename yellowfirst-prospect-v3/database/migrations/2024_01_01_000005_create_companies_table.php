<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Companies — the central object the agent researches.
 *
 * Domain is the natural identifier (used for email pattern detection,
 * deduplication across CSVs, and matching to news/funding sources).
 * It's unique per tenant.
 *
 * Score is the last computed signal score. Scores are recomputed
 * when new signals are detected. The history of scores is implicit
 * via signals_detected.weight_at_detection — we never re-score the
 * past, only the present.
 *
 * assigned_to_user_id was added in v2 of the prototype — the prototype
 * showed how this affects the dashboard, so the column exists from
 * day one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('domain')->comment('Primary website domain — used for email pattern matching');
            $table->string('linkedin_url', 500)->nullable();
            $table->string('crunchbase_url', 500)->nullable();
            $table->string('industry', 100)->nullable();
            $table->enum('size_bucket', ['small', 'mid', 'enterprise'])->nullable();
            $table->unsignedInteger('employee_count')->nullable();
            $table->string('headquarters_country', 100)->nullable();
            $table->string('headquarters_city', 100)->nullable();
            $table->foreignId('business_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('funding_total_usd')->nullable();
            $table->string('last_funding_round', 50)->nullable();
            $table->date('last_funding_date')->nullable();
            $table->unsignedSmallInteger('score')->default(0);
            $table->timestamp('score_computed_at')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_researched_at')->nullable();
            $table->enum('status', ['active', 'archived', 'blacklisted'])->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'domain']);
            $table->index(['tenant_id', 'score']);
            $table->index(['tenant_id', 'status', 'score']);
            $table->index(['tenant_id', 'assigned_to_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};

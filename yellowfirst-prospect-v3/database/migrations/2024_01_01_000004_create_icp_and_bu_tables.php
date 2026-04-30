<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ICP Profiles — Ideal Customer Profile config per tenant.
 *
 * Read from docs/02-icp-profiles.md on agent startup, then synced
 * here so the app can edit them through the UI without redeploying.
 *
 * Each profile has a weight — multiplied into signal scores.
 *   1.5 = bullseye (Series A-C SaaS for Yellowfirst)
 *   1.2 = aspirational (late-stage scaleups)
 *   1.0 = stretch (enterprise innovation)
 *
 * Business units segment the prospect pipeline geographically/by-org.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('icp_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('code', 50)->comment('series_a_c_saas, late_stage_scaleup, enterprise_innovation');
            $table->string('name');
            $table->decimal('weight', 3, 2)->default(1.00)->comment('Score multiplier');
            $table->json('industries')->comment('B2B SaaS, FinTech, etc.');
            $table->json('geography')->comment('US, EU, India');
            $table->string('size_min', 20)->nullable()->comment('e.g. 20');
            $table->string('size_max', 20)->nullable()->comment('e.g. 200');
            $table->json('funding_stages')->nullable();
            $table->json('target_titles_primary')->comment('CTO, VP Eng, Co-founder');
            $table->json('target_titles_secondary')->nullable();
            $table->json('signal_emphasis')->comment('Per-signal multipliers for this ICP');
            $table->text('pitch_angle')->comment('How the email should frame Yellowfirst for this ICP');
            $table->boolean('active')->default(true);
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'active']);
        });

        Schema::create('business_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->string('code', 50)->comment('us, eu, india_apac, australia');
            $table->string('name');
            $table->json('countries')->nullable()->comment('ISO 3166-1 alpha-2 codes');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_units');
        Schema::dropIfExists('icp_profiles');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenants — the root of the multi-tenant tree.
 *
 * Each tenant is a customer org (Yellowfirst, Bluefamily, etc.).
 * Every other table references this via tenant_id.
 *
 * White-label theming lives in theme_config (JSON) and overrides
 * the defaults in resources/css/tokens.css.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug', 100)->unique();
            $table->string('domain')->nullable()->comment('Custom domain for white-label, e.g. app.bluefamily.com');
            $table->json('theme_config')->nullable()->comment('Color/font/logo overrides');
            $table->string('timezone', 50)->default('UTC')->comment('Each tenant gets their own 8 AM');
            $table->unsignedTinyInteger('daily_quota_small')->default(5);
            $table->unsignedTinyInteger('daily_quota_mid')->default(3);
            $table->unsignedTinyInteger('daily_quota_enterprise')->default(1);
            $table->text('smtp_config')->nullable()->comment('Encrypted JSON: OAuth tokens or SMTP creds');
            $table->enum('status', ['active', 'suspended', 'trial'])->default('trial');
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CSV imports and the warm-connection graph.
 *
 * csv_imports: metadata about each uploaded file
 *   - owner_label is the friendly name ("Karna's connections", "Reez's network")
 *   - owner_type captures whether it's the user's own network, a friend's, or a teammate's
 *   - The actual file is stored on disk at tenants/{id}/csvs/...
 *
 * connections: one row per person in the CSV
 *   - We deliberately store duplicates (Karna AND Reez might both have
 *     John Doe) — preserves provenance per CSV. Deduplication is a UI concern.
 *   - The Apollo/Lusha-style format with verified emails is handled by the
 *     parser config in agent/sources/csv_format_apollo.yaml — the columns
 *     here are the canonical fields after parsing.
 *
 * connection_owners: who can warm-intro this connection
 *   - Lets us answer "Who in my team can intro me to people at high-signal companies?"
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('csv_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('uploaded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('owner_label')->comment('"Karna\'s connections", "Reez\'s connections"');
            $table->enum('owner_type', ['self', 'friend', 'teammate', 'other'])->default('self');
            $table->string('source_format', 50)->default('apollo')->comment('apollo, lusha, linkedin_native, custom');
            $table->string('filename');
            $table->string('storage_path', 500)->comment('tenants/{id}/csvs/...');
            $table->unsignedInteger('row_count')->default(0);
            $table->enum('status', ['uploading', 'parsing', 'ready', 'failed'])->default('uploading');
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'uploaded_by_user_id']);
        });

        Schema::create('connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('csv_import_id')->constrained()->cascadeOnDelete();
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('company_name')->nullable()->comment('As recorded in CSV');
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete()->comment('Resolved canonical company');
            $table->string('title')->nullable();
            $table->string('linkedin_url', 500)->nullable();
            $table->string('email')->nullable();
            $table->string('email_confidence_grade', 5)->nullable()->comment('Apollo: A+, A, B, etc.');
            $table->date('connected_on')->nullable();
            $table->unsignedTinyInteger('degree')->default(1)->comment('1 = direct, 2 = friend-of-friend');
            $table->string('location')->nullable();
            $table->unsignedSmallInteger('signal_score')->default(0)->comment('Inherited from connected company');
            $table->json('metadata')->nullable()->comment('Raw extra fields from source CSV');
            $table->timestamps();

            $table->index(['tenant_id', 'company_id']);
            $table->index(['tenant_id', 'csv_import_id']);
            $table->index(['tenant_id', 'signal_score']);
        });

        Schema::create('connection_owners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete()->comment('Null if owner is external (friend/family who is not a tenant user)');
            $table->string('owner_label')->comment('Display name when user_id is null');
            $table->boolean('can_introduce')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'connection_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connection_owners');
        Schema::dropIfExists('connections');
        Schema::dropIfExists('csv_imports');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('integrations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Set when the connection belongs to the whole organization (a
            // firm-wide connection made by an admin) rather than to one seat.
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('provider', 40);
            $table->string('status', 30)->default('connected');
            // Whether the connection serves one seat or the whole firm.
            $table->string('connection_scope', 20)->default('personal');
            // The provider-side account, shown so a user with several accounts
            // can tell which one is connected.
            $table->string('provider_account_id')->nullable();
            $table->string('account_email')->nullable();
            $table->string('account_name')->nullable();
            // Encrypted at rest; never serialized to any client.
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            // The OAuth scopes currently granted by the provider.
            $table->json('granted_scopes')->nullable();
            // Per-capability state: enabled flag, sync status, last sync, error.
            $table->json('capabilities')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->string('paused_reason')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'provider']);
            $table->index(['organization_id', 'provider']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('integrations');
    }
};

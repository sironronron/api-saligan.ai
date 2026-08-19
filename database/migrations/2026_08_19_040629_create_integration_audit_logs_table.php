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
        Schema::create('integration_audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // The user who performed the action. Kept without a constraint so
            // the audit trail survives account deletion.
            $table->unsignedBigInteger('user_id')->nullable();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            // Deliberately unconstrained: the trail must survive the deletion
            // of whatever it describes.
            $table->uuid('integration_id')->nullable();
            // Null for org-level actions (policy and connection-mode changes)
            // that belong to no provider in particular.
            $table->string('provider', 40)->nullable();
            $table->string('action', 50);
            // Capability key, scopes granted, or whatever else describes the
            // action — the audit row must make sense on its own.
            $table->json('details')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['organization_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['integration_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('integration_audit_logs');
    }
};

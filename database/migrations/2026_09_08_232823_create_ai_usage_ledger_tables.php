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
        // The budget window a subscription spends against: one monthly slice
        // cut from the subscription's own anniversary dates, so a renewal
        // moves the counters instead of the calendar month. Annual
        // subscriptions get twelve consecutive windows, not one yearly pool.
        Schema::create('subscription_windows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->dateTime('window_start');
            $table->dateTime('window_end');
            $table->decimal('budget_usd', 12, 6);
            $table->decimal('used_usd', 12, 6)->default(0);
            $table->dateTime('warned_80_at')->nullable();
            $table->timestamps();

            $table->unique(['subscription_id', 'window_start']);
            $table->index(['subscription_id', 'window_end']);
        });

        // One row per AI operation the product runs on a tenant's behalf —
        // chat turns, rewrites, research, letters, OCR, embeddings,
        // classification, digests. The reservation row is created before the
        // provider is called and settled with the measured cost after, so a
        // turn that never ran can never bill, and a turn that ran is always
        // reconcilable against the provider invoice.
        Schema::create('ai_usages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subscription_window_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('conversation_id')->nullable();
            $table->uuid('document_id')->nullable();
            $table->string('operation', 32);
            $table->string('engine', 16)->nullable();
            $table->string('provider', 32)->nullable();
            $table->string('model', 128)->nullable();
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('cache_read_tokens')->default(0);
            $table->unsignedBigInteger('cache_write_tokens')->default(0);
            $table->unsignedInteger('image_pages')->default(0);
            $table->unsignedInteger('grounding_queries')->default(0);
            $table->unsignedInteger('attempts')->default(1);
            // reserved → settled on success, released when nothing billable ran
            // (provider never started, turn discarded, ingestion failed).
            $table->string('status', 16)->default('reserved');
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->string('rate_version', 64)->nullable();
            $table->string('idempotency_key', 255)->nullable()->unique();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamps();

            $table->index(['subscription_id', 'status']);
            $table->index(['subscription_window_id', 'status']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::table('plans', function (Blueprint $table) {
            // Monthly AI spend allowance in USD cents (e.g. 515 = $5.15).
            // Null means the plan is not spend-gated (contract tiers).
            $table->unsignedInteger('ai_budget_usd_cents')->nullable()->after('overage_price');
        });

        Schema::table('documents', function (Blueprint $table) {
            // The ledger row whose reservation the ingestion settles or
            // releases. Null for rows predating usage metering.
            $table->uuid('ai_usage_id')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('ai_usage_id');
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('ai_budget_usd_cents');
        });

        // Referencing table first: ai_usages holds the foreign key to
        // subscription_windows, so reversing the creation order deadlocks
        // the rollback on Postgres.
        Schema::dropIfExists('ai_usages');
        Schema::dropIfExists('subscription_windows');
    }
};

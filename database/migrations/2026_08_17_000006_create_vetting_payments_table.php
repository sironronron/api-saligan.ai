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
        Schema::create('vetting_payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('vetting_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('submitter_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('lawyer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('gateway', 20)->default('paymongo');
            $table->string('kind', 20);
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('amount');
            $table->string('gateway_payment_intent_id', 100)->nullable();
            $table->string('gateway_payment_id', 100)->nullable();
            $table->string('gateway_payment_method_id', 100)->nullable();
            $table->string('gateway_refund_id', 100)->nullable();
            $table->string('receipt_ref', 100)->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('submitter_id');
            $table->index('lawyer_id');
            $table->index('gateway_payment_intent_id');
            $table->index(['status', 'kind']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vetting_payments');
    }
};

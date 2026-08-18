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
        Schema::create('vetting_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('submitter_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->string('document_type', 100);
            $table->string('summary', 500);
            $table->string('jurisdiction', 50)->nullable();
            $table->string('service_type', 20);
            $table->string('urgency', 20)->default('normal');
            $table->string('status', 30)->default('pending');
            $table->foreignId('assigned_lawyer_id')->nullable()->constrained('users')->nullOnDelete();

            // Fees in centavos. Notarization always carries its own fee; a
            // vetting fee applies only when an admin has configured one.
            $table->unsignedBigInteger('vetting_fee')->nullable();
            $table->unsignedBigInteger('notarization_fee')->nullable();

            $table->string('payment_status', 20)->default('none');
            $table->string('gateway_payment_intent_id', 100)->nullable();
            $table->string('gateway_checkout_url', 500)->nullable();

            $table->timestamp('deadline_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('session_scheduled_at')->nullable();
            $table->string('session_link', 500)->nullable();
            $table->string('session_provider', 50)->nullable();
            $table->string('certificate_number', 100)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('submitter_id');
            $table->index('assigned_lawyer_id');
            $table->index(['status', 'assigned_lawyer_id']);
            $table->index('gateway_payment_intent_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vetting_requests');
    }
};

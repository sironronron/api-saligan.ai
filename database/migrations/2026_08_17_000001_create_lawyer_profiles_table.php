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
        Schema::create('lawyer_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('full_name');
            $table->string('bar_number');
            $table->string('bar_jurisdiction', 100)->default('Integrated Bar of the Philippines');
            $table->string('ptr_number', 100)->nullable();
            $table->json('practice_areas')->nullable();
            $table->string('region', 50)->default('nationwide');
            $table->string('city', 100)->nullable();
            $table->string('phone', 30)->nullable();
            $table->boolean('is_notary')->default(false);
            $table->string('notarial_commission_number', 100)->nullable();
            $table->string('notarial_commission_issuer', 150)->nullable();
            $table->date('notarial_commission_expires_at')->nullable();
            $table->string('id_document_path')->nullable();
            $table->string('bar_membership_document_path')->nullable();
            $table->string('verification_status', 20)->default('pending');
            $table->text('verification_reason')->nullable();
            $table->timestamp('verification_reviewed_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->boolean('available')->default(false);
            $table->unsignedInteger('max_concurrent_assignments')->default(3);
            $table->boolean('notify_email')->default(true);
            $table->boolean('notify_sms')->default(false);
            $table->boolean('notify_push')->default(false);
            $table->boolean('notify_in_app')->default(true);
            // Stamped when practice areas or region change, so a change can
            // re-trigger a light verification pass without touching verified_at.
            $table->timestamp('profile_changed_at')->nullable();
            $table->timestamps();

            $table->unique('user_id');
            $table->index('verification_status');
            $table->index(['verification_status', 'available', 'region']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lawyer_profiles');
    }
};

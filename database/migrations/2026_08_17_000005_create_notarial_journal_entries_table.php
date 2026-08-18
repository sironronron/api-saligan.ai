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
        Schema::create('notarial_journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lawyer_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('vetting_request_id')->nullable()->constrained()->nullOnDelete();
            $table->string('signer_name', 255);
            $table->string('id_type', 100);
            $table->string('id_number', 150);
            $table->string('document_type', 100);
            $table->string('verification_method', 100)->default('remote_online_video');
            $table->string('certificate_number', 100)->nullable();
            $table->string('session_recording_ref', 255)->nullable();
            $table->timestamp('notarized_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('lawyer_id');
            $table->index('vetting_request_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notarial_journal_entries');
    }
};

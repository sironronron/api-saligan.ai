<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The queue of documents waiting to be filed by a batched classification.
     *
     * Batched classification trades latency for cost: the Anthropic Batches
     * API bills at half the standard rate, and filing is a suggestion nobody
     * is watching for, so a document can wait an hour to be sorted. A row here
     * is a document that has been ingested but not yet filed.
     */
    public function up(): void
    {
        Schema::create('document_classification_requests', function (Blueprint $table) {
            $table->id();

            // One row per document: re-ingesting replaces the pending request
            // rather than queueing the same document twice.
            $table->foreignUuid('document_id')->unique()->constrained()->cascadeOnDelete();

            // The rendered prompt — filename, title, and the opening of the
            // document. Encrypted at rest by the model's cast: the documents
            // themselves are stored encrypted, and an excerpt sitting in
            // plaintext here would undo that for the part a classifier reads.
            $table->text('prompt');

            // pending → submitted → succeeded | failed
            $table->string('status')->default('pending');

            // The Anthropic batch this request was submitted in, and the
            // reason it ended, when it ended badly.
            $table->string('batch_id')->nullable();
            $table->string('error')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            // The two sweeps: pick up pending work, and poll open batches.
            $table->index(['status', 'created_at']);
            $table->index(['batch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_classification_requests');
    }
};

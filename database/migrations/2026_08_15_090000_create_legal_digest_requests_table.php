<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The queue of crawled authorities waiting on a batched digest.
     *
     * Digests are written at crawl time and by the bulk backfill — work nobody
     * is watching for, done a few hundred pages at a time, which is exactly
     * what a batch API is for at half the token cost. Digests generated on
     * first read are not queued here: a reader is waiting on those, and a
     * batch takes up to a day.
     */
    public function up(): void
    {
        Schema::create('legal_digest_requests', function (Blueprint $table) {
            $table->id();

            // One row per page: re-crawling replaces the pending request
            // rather than queueing the same authority twice.
            $table->foreignUuid('crawled_page_id')->unique()->constrained()->cascadeOnDelete();

            // The rendered prompt — title plus the head and tail of the
            // authority. Public legal text, unlike the document classification
            // queue's excerpts, so it is stored as-is.
            $table->text('prompt');

            // pending → submitted → succeeded | failed
            $table->string('status')->default('pending');

            // The batch this request went out in, and the reason it ended,
            // when it ended badly.
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
        Schema::dropIfExists('legal_digest_requests');
    }
};

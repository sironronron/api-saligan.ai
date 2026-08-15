<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The caveats, gaps, and assumptions an answer carried. They used to exist
     * only as the "Caveats and next steps" prose at the bottom of a reply,
     * where they were routinely scrolled past; stored as rows they can be
     * surfaced on their own and, more importantly, answered.
     */
    public function up(): void
    {
        Schema::create('advisories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('conversation_id')->index();
            // The turn that raised it. Nullable because the assistant message
            // is persisted after the stream ends, while the tool that files
            // these runs during it.
            $table->uuid('message_id')->nullable()->index();
            $table->enum('kind', ['caveat', 'gap', 'risk', 'assumption', 'deadline'])->default('caveat');
            $table->string('title');
            $table->text('detail')->nullable();
            $table->enum('severity', ['low', 'medium', 'high'])->default('medium');
            // How the user answered it. 'open' is unanswered; the rest are the
            // four dispositions the review dialog offers.
            $table->enum('status', ['open', 'tracked', 'not_a_problem', 'will_check', 'mitigated'])->default('open');
            $table->text('note')->nullable();
            // Set when the user turns an advisory into a task, so the dialog can
            // point at the task it created instead of filing a second one.
            $table->uuid('todo_id')->nullable()->index();
            $table->timestamp('responded_at')->nullable();
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advisories');
    }
};

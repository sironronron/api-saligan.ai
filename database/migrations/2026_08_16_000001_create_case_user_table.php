<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who, besides the owner, is working a case. The owner stays on
     * `cases.user_id` — they are the one account billing counts a case
     * against, and that must not move when the work is shared out.
     */
    public function up(): void
    {
        Schema::create('case_user', function (Blueprint $table) {
            $table->id();
            $table->uuid('case_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Kept for the audit trail: who put this person on the case. Nulled
            // rather than cascaded, so removing an admin does not quietly drop
            // the assignments they made.
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->foreign('case_id')->references('id')->on('cases')->cascadeOnDelete();

            // One row per person per case: assigning twice is a no-op, not a
            // second seat on the same matter.
            $table->unique(['case_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_user');
    }
};

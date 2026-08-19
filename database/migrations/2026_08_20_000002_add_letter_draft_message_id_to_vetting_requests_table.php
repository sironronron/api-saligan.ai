<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vetting_requests', function (Blueprint $table) {
            $table->uuid('letter_draft_message_id')->nullable()->index();
            $table->foreign('letter_draft_message_id')->references('id')->on('messages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vetting_requests', function (Blueprint $table) {
            $table->dropForeign(['letter_draft_message_id']);
            $table->dropColumn('letter_draft_message_id');
        });
    }
};

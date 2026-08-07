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
        Schema::table('documents', function (Blueprint $table) {
            $table->uuid('case_id')->nullable()->after('user_id');
            $table->foreign('case_id')->references('id')->on('cases')->nullOnDelete();
            $table->index('case_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['case_id']);
            $table->dropForeign(['case_id']);
            $table->dropColumn('case_id');
        });
    }
};

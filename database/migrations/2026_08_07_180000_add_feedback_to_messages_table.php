<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->string('feedback', 10)->nullable()->after('metadata');
            $table->timestamp('feedback_at')->nullable()->after('feedback');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropColumn(['feedback', 'feedback_at']);
        });
    }
};

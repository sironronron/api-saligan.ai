<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Uploaded documents get the same digest treatment crawled authorities
     * already have, so a citation to the client's own contract can be read as
     * a summary before the reader commits to the full text.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->text('digest')->nullable()->after('error_message');
            $table->timestamp('digest_generated_at')->nullable()->after('digest');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropColumn(['digest', 'digest_generated_at']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Store the latest generated whole-matter digest and the source revision it
     * represents. The revision lets a queued generation discard stale output.
     */
    public function up(): void
    {
        Schema::table('cases', function (Blueprint $table): void {
            $table->text('digest')->nullable()->after('description');
            $table->timestamp('digest_generated_at')->nullable()->after('digest');
            $table->string('digest_source_hash', 64)->nullable()->after('digest_generated_at');
        });
    }

    public function down(): void
    {
        Schema::table('cases', function (Blueprint $table): void {
            $table->dropColumn(['digest', 'digest_generated_at', 'digest_source_hash']);
        });
    }
};

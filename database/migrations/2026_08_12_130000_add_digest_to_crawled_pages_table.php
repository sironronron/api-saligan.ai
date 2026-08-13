<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A plain-language digest of the crawled authority, generated once at
     * crawl time.
     *
     * Written on ingest rather than per request: a case digest is the same for
     * every reader, so generating it once and serving it from the row keeps
     * opening a source instant and costs one model call per document instead
     * of one per view.
     */
    public function up(): void
    {
        Schema::table('crawled_pages', function (Blueprint $table) {
            $table->text('digest')->nullable()->after('promulgation_date');
            $table->timestamp('digest_generated_at')->nullable()->after('digest');
        });
    }

    public function down(): void
    {
        Schema::table('crawled_pages', function (Blueprint $table) {
            $table->dropColumn(['digest', 'digest_generated_at']);
        });
    }
};

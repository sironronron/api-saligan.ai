<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            // A case may now host multiple conversations, one per purpose.
            $table->dropUnique(['case_id']);
            $table->string('purpose', 100)->nullable()->after('case_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('purpose');
        });

        // Only re-add the unique constraint if there are no duplicate case_id values.
        // Duplicates may exist from the period when the constraint was dropped.
        $hasDuplicates = DB::select('SELECT case_id FROM conversations WHERE case_id IS NOT NULL GROUP BY case_id HAVING COUNT(*) > 1');

        if ($hasDuplicates === []) {
            Schema::table('conversations', function (Blueprint $table) {
                $table->unique('case_id');
            });
        }
    }
};

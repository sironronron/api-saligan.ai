<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * `cases.organization_id` was added but never written: case creation set
     * only `user_id`, so every case carries a null organization regardless of
     * whether its owner belongs to a firm. Adopt the owner's organization for
     * the cases that have one, and bring existing matter memory in line with
     * the case it belongs to.
     *
     * Cases owned by a solo user stay null — that is the correct value, not a
     * gap to fill.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE cases
            SET organization_id = users.organization_id
            FROM users
            WHERE cases.user_id = users.id
              AND cases.organization_id IS NULL
              AND users.organization_id IS NOT NULL
        SQL);

        DB::statement(<<<'SQL'
            UPDATE matter_memory
            SET organization_id = cases.organization_id
            FROM cases
            WHERE matter_memory.case_id = cases.id
              AND matter_memory.organization_id IS DISTINCT FROM cases.organization_id
        SQL);
    }

    /**
     * Not reversible: the pre-migration state cannot be distinguished from a
     * legitimately null organization once the values are written.
     */
    public function down(): void
    {
        //
    }
};

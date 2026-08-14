<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Role and primary use became multi-select. Both now hold a comma-separated
     * list of keys, the same shape kyc_document_types already uses, so the
     * columns need the room a full list takes. Existing single-value rows stay
     * valid — a lone key is just a one-item list.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('kyc_role', 255)->nullable()->change();
            $table->string('kyc_use_case', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('users')->update([
            'kyc_role' => DB::raw('left(kyc_role, 40)'),
            'kyc_use_case' => DB::raw('left(kyc_use_case, 40)'),
        ]);

        Schema::table('users', function (Blueprint $table) {
            $table->string('kyc_role', 40)->nullable()->change();
            $table->string('kyc_use_case', 40)->nullable()->change();
        });
    }
};

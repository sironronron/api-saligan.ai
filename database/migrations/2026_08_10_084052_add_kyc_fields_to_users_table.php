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
        Schema::table('users', function (Blueprint $table) {
            $table->string('kyc_role', 40)->nullable()->after('org_status');
            $table->string('kyc_role_other', 255)->nullable()->after('kyc_role');
            $table->string('kyc_use_case', 40)->nullable()->after('kyc_role_other');
            $table->string('kyc_use_case_other', 255)->nullable()->after('kyc_use_case');
            $table->timestamp('kyc_completed_at')->nullable()->after('kyc_use_case_other');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'kyc_role',
                'kyc_role_other',
                'kyc_use_case',
                'kyc_use_case_other',
                'kyc_completed_at',
            ]);
        });
    }
};

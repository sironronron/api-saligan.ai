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
        Schema::table('organizations', function (Blueprint $table) {
            // Whether each member connects their own account (`per_seat`) or an
            // admin connects once on behalf of the whole firm (`firm_wide`).
            $table->string('integrations_connection_mode', 20)->default('per_seat');
            // Org-wide capability policy: capability key => forced_on|forced_off.
            // A capability absent here is each member's own choice.
            $table->json('integration_capability_policies')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['integrations_connection_mode', 'integration_capability_policies']);
        });
    }
};

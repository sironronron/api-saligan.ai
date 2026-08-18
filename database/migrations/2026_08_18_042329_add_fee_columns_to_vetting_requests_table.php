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
        Schema::table('vetting_requests', function (Blueprint $table) {
            // The property/contract value behind percentage-based notarial
            // fees (deeds, leases), in centavos.
            $table->unsignedBigInteger('property_value')->nullable()->after('notarization_fee');

            // The PayMongo processing fee passed through to the buyer, in
            // centavos; the total charged is the service fees plus this.
            $table->unsignedBigInteger('processing_fee')->nullable()->after('property_value');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vetting_requests', function (Blueprint $table) {
            $table->dropColumn(['property_value', 'processing_fee']);
        });
    }
};

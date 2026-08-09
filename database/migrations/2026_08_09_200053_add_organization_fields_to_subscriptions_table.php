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
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('id')
                ->constrained()->cascadeOnDelete()->index();
            $table->unsignedInteger('seats_purchased')->default(1)->after('plan_id');
            $table->unsignedBigInteger('price_per_seat')->nullable()->after('seats_purchased');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('organization_id');
            $table->dropColumn(['seats_purchased', 'price_per_seat']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Give a plan a team shape: how many people the list price covers, and
     * what one more costs.
     *
     * Until now `subscriptions.seats_purchased` and `price_per_seat` were
     * maintained by SeatBillingService but no plan said what a seat was worth,
     * so the per-seat price had to be invented at subscribe time. These two
     * columns move that decision onto the plan, where the pricing page can
     * read it.
     *
     * `seat_price` is nullable and means "this plan does not sell seats" —
     * the single-seat plans and the contract-priced one, whose seat terms are
     * agreed rather than listed.
     */
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->unsignedInteger('included_seats')->default(1)->after('overage_price');
            $table->unsignedBigInteger('seat_price')->nullable()->after('included_seats');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->dropColumn(['included_seats', 'seat_price']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Give back the seats that plan upgrades never handed over.
     *
     * `seats_purchased` was written once, at subscription creation, from the
     * plan's `included_seats`; changing plans afterwards updated the plan and
     * the seat price but left the count behind. Anyone who moved up to Firm
     * from a single-seat plan has been paying for three seats and able to use
     * one. This raises every subscription to at least what its current plan
     * includes.
     *
     * It only ever raises. Subscriptions sitting above their plan's included
     * seats bought those seats deliberately, and Business subscriptions carry
     * negotiated counts against a one-seat plan row — both are left alone.
     */
    public function up(): void
    {
        // A correlated subquery rather than an update-with-join: Postgres does
        // not accept the join form Laravel builds for it.
        $includedSeats = 'select included_seats from plans where plans.id = subscriptions.plan_id';

        DB::statement(
            "update subscriptions
                set seats_purchased = ({$includedSeats})
              where seats_purchased < ({$includedSeats})"
        );
    }

    /**
     * Not reversible: the pre-backfill counts are the bug, and lowering seats
     * again would lock out members who have since taken them.
     */
    public function down(): void
    {
        //
    }
};

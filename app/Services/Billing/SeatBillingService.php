<?php

namespace App\Services\Billing;

use App\Models\BillingEvent;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;

class SeatBillingService
{
    /**
     * Purchase additional seats for the organization, logging the change as a
     * billing event so the next invoice reflects the new seat count.
     */
    public function addSeats(Organization $organization, User $actor, int $quantity = 1): Subscription
    {
        abort_if($quantity < 1, 422, 'Seat quantity must be at least 1.');
        abort_unless($organization->canManage($actor), 403, 'Only organization admins can change seat counts.');

        $subscription = $this->requireSubscription($organization);

        $seatsBefore = $subscription->seats_purchased;
        $seatsAfter = $seatsBefore + $quantity;

        $subscription->update(['seats_purchased' => $seatsAfter]);

        $this->log($subscription, BillingEvent::EVENT_SEAT_ADDED, $seatsBefore, $seatsAfter, $actor, $quantity);

        return $subscription->fresh();
    }

    /**
     * Remove purchased seats, blocked when the reduction would go below the
     * number of active members.
     */
    public function removeSeats(Organization $organization, User $actor, int $quantity = 1): Subscription
    {
        abort_if($quantity < 1, 422, 'Seat quantity must be at least 1.');
        abort_unless($organization->canManage($actor), 403, 'Only organization admins can change seat counts.');

        $subscription = $this->requireSubscription($organization);

        $seatsBefore = $subscription->seats_purchased;
        $seatsAfter = $seatsBefore - $quantity;

        $activeMembers = $organization->seatsUsed();

        abort_if($seatsAfter < $activeMembers, 422, "You cannot reduce seats below the {$activeMembers} active member(s) of your organization. Remove members first.");

        $subscription->update(['seats_purchased' => $seatsAfter]);

        $this->log($subscription, BillingEvent::EVENT_SEAT_REMOVED, $seatsBefore, $seatsAfter, $actor, $quantity);

        return $subscription->fresh();
    }

    /**
     * The amount the next self-managed invoice should charge, in centavos:
     * seats purchased times the per-seat price.
     */
    public function nextInvoiceAmount(Subscription $subscription): int
    {
        return $subscription->nextInvoiceAmount();
    }

    /**
     * The subscription governing the organization, or a clear error when the
     * organization is not on an active subscription.
     */
    protected function requireSubscription(Organization $organization): Subscription
    {
        $subscription = $organization->subscription;

        abort_if($subscription === null, 422, 'Your organization does not have a subscription yet.');

        return $subscription;
    }

    /**
     * Record a seat change on the subscription's billing ledger.
     */
    protected function log(
        Subscription $subscription,
        string $eventType,
        int $seatsBefore,
        int $seatsAfter,
        User $actor,
        int $quantity,
    ): void {
        $subscription->billingEvents()->create([
            'event_type' => $eventType,
            'seats_before' => $seatsBefore,
            'seats_after' => $seatsAfter,
            'price_per_seat' => $subscription->price_per_seat,
            'metadata' => [
                'actor_user_id' => $actor->id,
                'actor_email' => $actor->email,
                'quantity' => $quantity,
            ],
            'occurred_at' => now(),
        ]);
    }
}

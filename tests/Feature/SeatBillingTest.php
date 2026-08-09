<?php

use App\Models\BillingEvent;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;

beforeEach(function () {
    $this->organization = Organization::factory()->create(['name' => 'Acme Law Office']);
    $this->owner = User::factory()->ownerOf($this->organization)->create();
    $this->plan = Plan::factory()->pro()->create();
    $this->subscription = Subscription::factory()->for($this->organization)->for($this->owner)->create([
        'plan_id' => $this->plan->id,
        'seats_purchased' => 2,
        'price_per_seat' => 200000,
    ]);
});

it('purchases additional seats and logs a billing event', function () {
    $this->actingAs($this->owner)
        ->postJson('/api/subscription/seats', ['quantity' => 2])
        ->assertOk()
        ->assertJsonPath('data.seats.purchased', 4)
        ->assertJsonPath('data.seats.next_invoice_amount', 800000)
        ->assertJsonPath('data.seats.next_invoice_pesos', 8000);

    expect($this->subscription->fresh()->seats_purchased)->toBe(4);

    $event = BillingEvent::firstWhere('subscription_id', $this->subscription->id);
    expect($event->event_type)->toBe(BillingEvent::EVENT_SEAT_ADDED)
        ->and($event->seats_before)->toBe(2)
        ->and($event->seats_after)->toBe(4)
        ->and($event->metadata['actor_user_id'])->toBe($this->owner->id);
});

it('removes purchased seats and logs a billing event', function () {
    $this->actingAs($this->owner)
        ->deleteJson('/api/subscription/seats', ['quantity' => 1])
        ->assertOk()
        ->assertJsonPath('data.seats.purchased', 1)
        ->assertJsonPath('data.seats.next_invoice_amount', 200000);

    $event = BillingEvent::firstWhere('subscription_id', $this->subscription->id);
    expect($event->event_type)->toBe(BillingEvent::EVENT_SEAT_REMOVED)
        ->and($event->seats_before)->toBe(2)
        ->and($event->seats_after)->toBe(1);
});

it('blocks reducing seats below the active member count', function () {
    User::factory()->memberOf($this->organization)->create();

    // 2 seats purchased, 2 active members.
    $this->actingAs($this->owner)
        ->deleteJson('/api/subscription/seats', ['quantity' => 1])
        ->assertStatus(422)
        ->assertJsonPath('message', 'You cannot reduce seats below the 2 active member(s) of your organization. Remove members first.');

    expect($this->subscription->fresh()->seats_purchased)->toBe(2);
});

it('computes the next invoice as seats purchased times price per seat', function () {
    expect($this->subscription->nextInvoiceAmount())->toBe(400000);

    $this->subscription->update(['seats_purchased' => 5]);

    expect($this->subscription->nextInvoiceAmount())->toBe(1000000);
});

it('falls back to the plan price when no per-seat price is set', function () {
    $this->subscription->update(['price_per_seat' => null, 'seats_purchased' => 3]);

    expect($this->subscription->nextInvoiceAmount())->toBe($this->plan->price * 3);
});

it('blocks non-admins from changing seat counts', function () {
    $member = User::factory()->memberOf($this->organization)->create();

    $this->actingAs($member)
        ->postJson('/api/subscription/seats', ['quantity' => 1])
        ->assertStatus(403);

    $this->actingAs($member)
        ->deleteJson('/api/subscription/seats', ['quantity' => 1])
        ->assertStatus(403);
});

it('rejects invalid seat quantities', function () {
    $this->actingAs($this->owner)
        ->postJson('/api/subscription/seats', ['quantity' => 0])
        ->assertUnprocessable();

    $this->actingAs($this->owner)
        ->postJson('/api/subscription/seats', ['quantity' => 101])
        ->assertUnprocessable();
});

it('requires an organization subscription before changing seats', function () {
    $this->subscription->delete();

    $this->actingAs($this->owner)
        ->postJson('/api/subscription/seats', ['quantity' => 1])
        ->assertStatus(422)
        ->assertJsonPath('message', 'Your organization does not have a subscription yet.');
});

it('exposes seat fields on the subscription resource', function () {
    $this->actingAs($this->owner)->getJson('/api/subscription')
        ->assertOk()
        ->assertJsonPath('data.seats.purchased', 2)
        ->assertJsonPath('data.seats.price_per_seat', 200000)
        ->assertJsonPath('data.seats.next_invoice_amount', 400000);
});

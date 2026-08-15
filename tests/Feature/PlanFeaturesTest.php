<?php

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\PlanFeatures;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Give the user a subscription on a plan carrying exactly these features.
 *
 * @param  list<string>  $features
 */
function userWithFeatures(array $features): User
{
    $user = User::factory()->create();
    $plan = Plan::factory()->create(['features' => $features]);

    Subscription::factory()->for($user)->create(['plan_id' => $plan->id]);

    return $user;
}

it('reports a feature the plan carries', function () {
    $user = userWithFeatures([PlanFeatures::DRAFTING, PlanFeatures::EXPORTS]);

    expect(PlanFeatures::has($user, PlanFeatures::DRAFTING))->toBeTrue()
        ->and(PlanFeatures::has($user, PlanFeatures::EXPORTS))->toBeTrue();
});

it('reports a feature the plan does not carry', function () {
    $user = userWithFeatures([PlanFeatures::DRAFTING]);

    expect(PlanFeatures::has($user, PlanFeatures::TEAMS))->toBeFalse();
});

it('grants every feature to an admin', function () {
    $user = User::factory()->create(['is_admin' => true]);

    foreach (PlanFeatures::capabilities() as $feature) {
        expect(PlanFeatures::has($user, $feature))->toBeTrue();
    }
});

it('treats a plan with no features at all as carrying none', function () {
    $user = userWithFeatures([]);

    expect(PlanFeatures::has($user, PlanFeatures::DRAFTING))->toBeFalse();
});

it('passes ensureHas through when the plan carries the feature', function () {
    $user = userWithFeatures([PlanFeatures::DEEP_RESEARCH]);

    PlanFeatures::ensureHas($user, PlanFeatures::DEEP_RESEARCH);
})->throwsNoExceptions();

it('refuses with a 402 the client can show an upgrade prompt for', function () {
    $user = userWithFeatures([PlanFeatures::DRAFTING]);

    try {
        PlanFeatures::ensureHas($user, PlanFeatures::TEAMS);
        $this->fail('Expected ensureHas to abort.');
    } catch (HttpResponseException $e) {
        $response = $e->getResponse();
        $payload = json_decode($response->getContent(), true);

        expect($response->getStatusCode())->toBe(402)
            ->and($payload['upgrade_required'])->toBeTrue()
            // Named after the thing they tried to do, not the feature key.
            ->and($payload['message'])->toContain('Team accounts');
    }
});

it('tells someone with no subscription to subscribe rather than to upgrade', function () {
    $user = User::factory()->create();

    try {
        PlanFeatures::ensureHas($user, PlanFeatures::DRAFTING);
        $this->fail('Expected ensureHas to abort.');
    } catch (HttpResponseException $e) {
        $payload = json_decode($e->getResponse()->getContent(), true);

        expect($e->getResponse()->getStatusCode())->toBe(402)
            ->and($payload['message'])->toBe('Subscribe to a plan to access Saligan.ai.');
    }
});

it('describes every feature it declares', function () {
    $catalogue = PlanFeatures::catalogue();

    $declared = collect((new ReflectionClass(PlanFeatures::class))->getConstants())
        ->reject(fn ($value, string $name): bool => str_starts_with($name, 'GROUP_'))
        ->values();

    foreach ($declared as $feature) {
        expect($catalogue)->toHaveKey($feature);
    }

    foreach ($catalogue as $entry) {
        expect($entry['label'])->not->toBeEmpty()
            ->and($entry['description'])->not->toBeEmpty()
            ->and($entry['group'])->toBeIn([PlanFeatures::GROUP_CAPABILITY, PlanFeatures::GROUP_SERVICE]);
    }
});

it('separates enforced capabilities from contractual services', function () {
    expect(PlanFeatures::capabilities())
        ->toContain(PlanFeatures::DRAFTING, PlanFeatures::TEAMS, PlanFeatures::FRONTIER_MODEL)
        ->not->toContain(PlanFeatures::SUPPORT_24_7, PlanFeatures::GUIDED_SETUP);
});

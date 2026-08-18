<?php

use App\Enums\VettingPaymentStatus;
use App\Enums\VettingRequestStatus;
use App\Models\LawyerPayout;
use App\Models\LawyerProfile;
use App\Models\NotarialJournalEntry;
use App\Models\User;
use App\Models\VettingPayment;
use App\Models\VettingRequest;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->member = User::factory()->create();
});

function notaryLawyer(array $attributes = []): array
{
    $lawyer = User::factory()->create();
    $profile = LawyerProfile::factory()->notary()->for($lawyer)->create($attributes);

    return [$lawyer, $profile];
}

it('rejects non-admin users from the vetting admin endpoints', function () {
    $this->signInAs($this->member)
        ->getJson('/api/admin/vetting/settings')
        ->assertForbidden();

    $this->signInAs($this->member)
        ->putJson('/api/admin/vetting/settings', [
            'fees' => ['notarization_fee' => 50000],
        ])->assertForbidden();

    $this->signInAs($this->member)
        ->getJson('/api/admin/vetting/reports/summary')
        ->assertForbidden();

    $this->signInAs($this->member)
        ->getJson('/api/admin/vetting/reports/lawyers')
        ->assertForbidden();

    $this->signInAs($this->member)
        ->getJson('/api/admin/lawyer-payouts')
        ->assertForbidden();
});

it('serves the current settings and defaults to admins', function () {
    $this->signInAs($this->admin)
        ->getJson('/api/admin/vetting/settings')
        ->assertOk()
        ->assertJsonPath('defaults.vetting_fee', config('vetting.default_vetting_fee'))
        ->assertJsonPath('defaults.notarization_fee', config('vetting.default_notarization_fee'))
        ->assertJsonPath('defaults.commission_percent', (int) config('vetting.platform_commission_percent'));
});

it('persists fee and rule changes from the admin panel', function () {
    $this->signInAs($this->admin)
        ->putJson('/api/admin/vetting/settings', [
            'fees' => [
                'notarization_fee' => 35000,
                'vetting_fee' => 5000,
                'overrides' => ['deed_of_sale' => 40000],
            ],
            'rules' => [
                'commission_percent' => 12,
                'escalation_hours' => 18,
                'max_concurrent_assignments' => 4,
                'match_pool_size' => 2,
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.fees.notarization_fee', 35000)
        ->assertJsonPath('data.fees.overrides.deed_of_sale', 40000)
        ->assertJsonPath('data.rules.commission_percent', 12);

    $this->signInAs($this->admin)
        ->getJson('/api/admin/vetting/settings')
        ->assertOk()
        ->assertJsonPath('data.fees.vetting_fee', 5000)
        ->assertJsonPath('data.rules.escalation_hours', 18)
        ->assertJsonPath('data.rules.match_pool_size', 2);
});

it('summarizes vetting volumes and notarization revenue', function () {
    $submitter = User::factory()->create();

    VettingRequest::factory()->for($submitter, 'submitter')->create([
        'status' => VettingRequestStatus::Completed,
        'completed_at' => now(),
    ]);

    VettingRequest::factory()->for($submitter, 'submitter')->create([
        'status' => VettingRequestStatus::Matched,
    ]);

    VettingPayment::factory()->for(
        VettingRequest::factory()->for($submitter, 'submitter')->create(['status' => VettingRequestStatus::Completed]),
    )->create([
        'submitter_id' => $submitter->id,
        'kind' => VettingPayment::KIND_NOTARIZATION,
        'amount' => 50000,
        'status' => VettingPaymentStatus::Captured,
        'gateway_payment_intent_id' => 'pi_test123',
    ]);

    $this->signInAs($this->admin)
        ->getJson('/api/admin/vetting/reports/summary')
        ->assertOk()
        ->assertJsonPath('data.requests.total', 3)
        ->assertJsonPath('data.requests.open', 1)
        ->assertJsonPath('data.requests.completed', 2)
        ->assertJsonPath('data.revenue.notarization', 50000)
        ->assertJsonPath('data.notarization_count', 1)
        ->assertJsonPath('data.acceptance_rate', 66.7);
});

it('reports per-lawyer workload and earnings', function () {
    [$lawyer] = notaryLawyer(['practice_areas' => ['real_estate']]);

    $submitter = User::factory()->create();

    $request = VettingRequest::factory()->for($submitter, 'submitter')->create([
        'status' => VettingRequestStatus::Completed,
        'assigned_lawyer_id' => $lawyer->id,
        'completed_at' => now(),
    ]);

    VettingPayment::factory()->for($request)->create([
        'submitter_id' => $submitter->id,
        'lawyer_id' => $lawyer->id,
        'kind' => VettingPayment::KIND_NOTARIZATION,
        'amount' => 100000,
        'status' => VettingPaymentStatus::Captured,
        'gateway_payment_intent_id' => 'pi_test123',
    ]);

    $response = $this->signInAs($this->admin)
        ->getJson('/api/admin/vetting/reports/lawyers')
        ->assertOk();

    $row = collect($response->json('data'))->firstWhere(fn (array $row) => $row['profile']['user_id'] === $lawyer->id);

    expect($row['accepted_total'])->toBe(1)
        ->and($row['completed_total'])->toBe(1)
        ->and($row['notarization_count'])->toBe(1)
        ->and($row['revenue'])->toBe(100000)
        ->and($row['platform_fee'])->toBe(10000)
        ->and($row['lawyer_share'])->toBe(90000);
});

it('lists payouts and marks them paid once', function () {
    [$lawyer] = notaryLawyer();

    $payout = LawyerPayout::factory()->for($lawyer, 'lawyer')->create([
        'gross_amount' => 100000,
        'platform_fee' => 10000,
        'lawyer_share' => 90000,
    ]);

    $this->signInAs($this->admin)
        ->getJson('/api/admin/lawyer-payouts')
        ->assertOk()
        ->assertJsonPath('data.0.lawyer_id', $lawyer->id)
        ->assertJsonPath('data.0.status', LawyerPayout::STATUS_PENDING);

    $this->signInAs($this->admin)
        ->postJson("/api/admin/lawyer-payouts/{$payout->id}/mark-paid", [
            'payout_ref' => 'PM-2026-0001',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', LawyerPayout::STATUS_PAID)
        ->assertJsonPath('data.payout_ref', 'PM-2026-0001');

    $this->assertDatabaseHas('lawyer_payouts', [
        'id' => $payout->id,
        'status' => LawyerPayout::STATUS_PAID,
    ]);

    $this->signInAs($this->admin)
        ->postJson("/api/admin/lawyer-payouts/{$payout->id}/mark-paid")
        ->assertStatus(422);
});

it('shows a lawyer only their own journal entries', function () {
    [$lawyerA] = notaryLawyer();
    [$lawyerB] = notaryLawyer();

    $entryA = NotarialJournalEntry::factory()->for($lawyerA, 'lawyer')->create();
    $entryB = NotarialJournalEntry::factory()->for($lawyerB, 'lawyer')->create();

    $this->signInAs($lawyerA)
        ->getJson('/api/lawyer/journal')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $entryA->id);

    $this->signInAs($lawyerB)
        ->getJson('/api/lawyer/journal')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $entryB->id);
});

it('lets an admin audit any lawyers journal and filter by lawyer', function () {
    [$lawyerA] = notaryLawyer();
    [$lawyerB] = notaryLawyer();

    NotarialJournalEntry::factory()->for($lawyerA, 'lawyer')->create();
    NotarialJournalEntry::factory()->for($lawyerB, 'lawyer')->create();

    $this->signInAs($this->admin)
        ->getJson('/api/lawyer/journal')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->signInAs($this->admin)
        ->getJson("/api/lawyer/journal?lawyer_id={$lawyerA->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.lawyer_id', $lawyerA->id);
});

<?php

use App\Models\User;
use App\Support\UserProfile;

it('saves a completed onboarding profile', function () {
    $user = User::factory()->create();

    $this->signInAs($user)
        ->putJson('/api/kyc', [
            'kyc_role' => UserProfile::ROLE_LAWYER,
            'kyc_use_case' => UserProfile::USE_CASE_CLIENT_WORK,
        ])
        ->assertOk()
        ->assertJsonPath('data.kyc_role', UserProfile::ROLE_LAWYER)
        ->assertJsonPath('data.kyc_use_case', UserProfile::USE_CASE_CLIENT_WORK)
        ->assertJsonPath('data.kyc_completed_at', fn ($value) => $value !== null);

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'kyc_role' => UserProfile::ROLE_LAWYER,
        'kyc_use_case' => UserProfile::USE_CASE_CLIENT_WORK,
    ]);
});

it('requires the free-text answer when the role is other', function () {
    $user = User::factory()->create();

    $this->signInAs($user)
        ->putJson('/api/kyc', [
            'kyc_role' => UserProfile::ROLE_OTHER,
            'kyc_use_case' => UserProfile::USE_CASE_LEGAL_RESEARCH,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('kyc_role_other');
});

it('stores the free-text answer when the selection is other', function () {
    $user = User::factory()->create();

    $this->signInAs($user)
        ->putJson('/api/kyc', [
            'kyc_role' => UserProfile::ROLE_OTHER,
            'kyc_role_other' => 'Community organizer at a farmers cooperative',
            'kyc_use_case' => UserProfile::USE_CASE_OTHER,
            'kyc_use_case_other' => 'Helping my barangay with titling paperwork',
        ])
        ->assertOk()
        ->assertJsonPath('data.kyc_role_other', 'Community organizer at a farmers cooperative')
        ->assertJsonPath('data.kyc_use_case_other', 'Helping my barangay with titling paperwork');
});

it('clears the free-text answer when the selection is no longer other', function () {
    $user = User::factory()->create([
        'kyc_role' => UserProfile::ROLE_OTHER,
        'kyc_role_other' => 'Community organizer',
        'kyc_use_case' => UserProfile::USE_CASE_LEGAL_RESEARCH,
        'kyc_completed_at' => now(),
    ]);

    $this->signInAs($user)
        ->putJson('/api/kyc', [
            'kyc_role' => UserProfile::ROLE_FARMER,
            'kyc_use_case' => UserProfile::USE_CASE_AGRARIAN_LAND,
        ])
        ->assertOk()
        ->assertJsonPath('data.kyc_role', UserProfile::ROLE_FARMER)
        ->assertJsonPath('data.kyc_role_other', null)
        ->assertJsonPath('data.kyc_use_case_other', null);
});

it('rejects an unknown role value', function () {
    $user = User::factory()->create();

    $this->signInAs($user)
        ->putJson('/api/kyc', [
            'kyc_role' => 'supreme-court-justice',
            'kyc_use_case' => UserProfile::USE_CASE_LEGAL_RESEARCH,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('kyc_role');
});

it('does not move kyc_completed_at backwards when the profile is edited', function () {
    $completedAt = now()->subDays(3)->startOfSecond();

    $user = User::factory()->create([
        'kyc_role' => UserProfile::ROLE_LAW_STUDENT,
        'kyc_use_case' => UserProfile::USE_CASE_LEARNING,
        'kyc_completed_at' => $completedAt,
    ]);

    $this->signInAs($user)
        ->putJson('/api/kyc', [
            'kyc_role' => UserProfile::ROLE_LAWYER,
            'kyc_use_case' => UserProfile::USE_CASE_CLIENT_WORK,
        ])
        ->assertOk();

    expect($user->fresh()->kyc_completed_at->eq($completedAt))->toBeTrue();
});

it('clears the onboarding profile', function () {
    $user = User::factory()->create([
        'kyc_role' => UserProfile::ROLE_LAWYER,
        'kyc_use_case' => UserProfile::USE_CASE_CLIENT_WORK,
        'kyc_completed_at' => now(),
    ]);

    $this->signInAs($user)
        ->deleteJson('/api/kyc')
        ->assertNoContent();

    $fresh = $user->fresh();

    expect($fresh->kyc_role)->toBeNull()
        ->and($fresh->kyc_role_other)->toBeNull()
        ->and($fresh->kyc_use_case)->toBeNull()
        ->and($fresh->kyc_use_case_other)->toBeNull()
        ->and($fresh->kyc_completed_at)->toBeNull();
});

it('returns the current profile and selectable options', function () {
    $user = User::factory()->create([
        'kyc_role' => UserProfile::ROLE_NOTARY_PUBLIC,
        'kyc_use_case' => UserProfile::USE_CASE_OWN_TRANSACTION,
    ]);

    $this->signInAs($user)
        ->getJson('/api/kyc')
        ->assertOk()
        ->assertJsonPath('data.kyc_role', UserProfile::ROLE_NOTARY_PUBLIC)
        ->assertJsonPath('data.kyc_use_case', UserProfile::USE_CASE_OWN_TRANSACTION)
        ->assertJsonPath('meta.role_options.0.value', UserProfile::ROLE_PRIVATE_INDIVIDUAL)
        ->assertJsonPath('meta.use_case_options.7.value', UserProfile::USE_CASE_OTHER);
});

it('exposes the kyc fields on the user resource', function () {
    $user = User::factory()->create([
        'kyc_role' => UserProfile::ROLE_REAL_ESTATE_BROKER,
        'kyc_use_case' => UserProfile::USE_CASE_OWN_TRANSACTION,
        'kyc_completed_at' => now(),
    ]);

    $this->signInAs($user)
        ->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('data.kyc_role', UserProfile::ROLE_REAL_ESTATE_BROKER)
        ->assertJsonPath('data.kyc_use_case', UserProfile::USE_CASE_OWN_TRANSACTION);
});

it('requires authentication for the kyc endpoints', function () {
    $this->getJson('/api/kyc')->assertStatus(401);
    $this->putJson('/api/kyc', [
        'kyc_role' => UserProfile::ROLE_LAWYER,
        'kyc_use_case' => UserProfile::USE_CASE_CLIENT_WORK,
    ])->assertStatus(401);
    $this->deleteJson('/api/kyc')->assertStatus(401);
});

it('saves several roles and primary uses as a comma-separated list', function () {
    $user = User::factory()->create();

    $this->signInAs($user)
        ->putJson('/api/kyc', [
            'kyc_role' => [UserProfile::ROLE_LAWYER, UserProfile::ROLE_NOTARY_PUBLIC],
            'kyc_use_case' => [UserProfile::USE_CASE_CLIENT_WORK, UserProfile::USE_CASE_LEGAL_RESEARCH],
        ])
        ->assertOk()
        ->assertJsonPath('data.kyc_role', UserProfile::ROLE_LAWYER.','.UserProfile::ROLE_NOTARY_PUBLIC)
        ->assertJsonPath('data.kyc_use_case', UserProfile::USE_CASE_CLIENT_WORK.','.UserProfile::USE_CASE_LEGAL_RESEARCH);
});

it('keeps the free-text answer when other is one of several roles', function () {
    $user = User::factory()->create();

    $this->signInAs($user)
        ->putJson('/api/kyc', [
            'kyc_role' => [UserProfile::ROLE_FARMER, UserProfile::ROLE_OTHER],
            'kyc_role_other' => 'Cooperative officer',
            'kyc_use_case' => [UserProfile::USE_CASE_AGRARIAN_LAND],
        ])
        ->assertOk()
        ->assertJsonPath('data.kyc_role_other', 'Cooperative officer');
});

it('rejects more selections than the cap allows', function () {
    $user = User::factory()->create();

    $this->signInAs($user)
        ->putJson('/api/kyc', [
            'kyc_role' => [
                UserProfile::ROLE_LAWYER,
                UserProfile::ROLE_NOTARY_PUBLIC,
                UserProfile::ROLE_PARALEGAL,
                UserProfile::ROLE_BUSINESS_OWNER,
            ],
            'kyc_use_case' => [UserProfile::USE_CASE_CLIENT_WORK],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('kyc_role');
});

it('does not let a repeated key eat into the selection cap', function () {
    $user = User::factory()->create();

    $this->signInAs($user)
        ->putJson('/api/kyc', [
            'kyc_role' => [UserProfile::ROLE_LAWYER, UserProfile::ROLE_LAWYER, UserProfile::ROLE_LAWYER, UserProfile::ROLE_LAWYER],
            'kyc_use_case' => [UserProfile::USE_CASE_CLIENT_WORK],
        ])
        ->assertOk()
        ->assertJsonPath('data.kyc_role', UserProfile::ROLE_LAWYER);
});

it('rejects an empty selection', function () {
    $user = User::factory()->create();

    $this->signInAs($user)
        ->putJson('/api/kyc', [
            'kyc_role' => [],
            'kyc_use_case' => [UserProfile::USE_CASE_CLIENT_WORK],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('kyc_role');
});

it('rejects an unknown key among valid ones', function () {
    $user = User::factory()->create();

    $this->signInAs($user)
        ->putJson('/api/kyc', [
            'kyc_role' => [UserProfile::ROLE_LAWYER, 'supreme-court-justice'],
            'kyc_use_case' => [UserProfile::USE_CASE_CLIENT_WORK],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('kyc_role');
});

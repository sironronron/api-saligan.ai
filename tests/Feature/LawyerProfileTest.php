<?php

use App\Enums\LawyerVerificationStatus;
use App\Models\LawyerProfile;
use App\Models\User;
use App\Notifications\LawyerVerificationResult;
use App\Services\Documents\DocumentEncryptor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

it('registers a lawyer profile and queues it for verification', function () {
    $user = User::factory()->create();

    $this->signInAs($user)
        ->postJson('/api/lawyer/profile', [
            'full_name' => 'Atty. Maria Santos',
            'bar_number' => '12345',
            'bar_jurisdiction' => 'Integrated Bar of the Philippines',
            'ptr_number' => '1234567',
            'practice_areas' => ['contracts', 'real_estate'],
            'region' => 'ncr',
            'city' => 'Makati',
            'phone' => '+639171234567',
            'is_notary' => true,
            'notarial_commission_number' => 'NC-2026-001',
            'notarial_commission_issuer' => 'Office of the Court Administrator',
            'notarial_commission_expires_at' => now()->addYear()->toDateString(),
            'id_document' => UploadedFile::fake()->createWithContent('id.pdf', 'id-bytes'),
            'bar_membership_document' => UploadedFile::fake()->createWithContent('bar.pdf', 'bar-bytes'),
        ])
        ->assertCreated();

    $profile = $user->lawyerProfile;

    expect($profile)->not->toBeNull()
        ->and($profile->full_name)->toBe('Atty. Maria Santos')
        ->and($profile->verification_status)->toBe(LawyerVerificationStatus::Pending)
        ->and($profile->is_notary)->toBeTrue()
        ->and($profile->id_document_path)->not->toBeNull()
        ->and($profile->bar_membership_document_path)->not->toBeNull()
        ->and($profile->available)->toBeFalse();
});

it('lists practice areas with the document types each covers', function () {
    $user = User::factory()->create();

    $response = $this->signInAs($user)
        ->getJson('/api/lawyer/profile')
        ->assertOk();

    $options = $response->json('meta.practice_area_options');

    $realEstate = collect($options)->firstWhere('value', 'real_estate');
    $litigation = collect($options)->firstWhere('value', 'litigation');

    expect($realEstate['documents'])->toContain('Deed of Absolute Sale')
        ->and($litigation['documents'])->toContain('Affidavit', 'Complaint')
        ->and(collect($options)->firstWhere('value', 'tax')['documents'])->toBe([]);
});

it('encrypts credential documents at rest', function () {
    $user = User::factory()->create();

    $this->signInAs($user)
        ->postJson('/api/lawyer/profile', [
            'full_name' => 'Atty. Maria Santos',
            'bar_number' => '12345',
            'bar_jurisdiction' => 'Integrated Bar of the Philippines',
            'practice_areas' => ['contracts'],
            'region' => 'ncr',
            'id_document' => UploadedFile::fake()->createWithContent('id.pdf', 'id-bytes'),
            'bar_membership_document' => UploadedFile::fake()->createWithContent('bar.pdf', 'bar-bytes'),
        ])
        ->assertCreated();

    $profile = $user->lawyerProfile;

    expect(app(DocumentEncryptor::class)->isEncrypted($profile->id_document_path))->toBeTrue();
});

it('requires both credential documents to register', function () {
    $user = User::factory()->create();

    $this->signInAs($user)
        ->postJson('/api/lawyer/profile', [
            'full_name' => 'Atty. Maria Santos',
            'bar_number' => '12345',
            'bar_jurisdiction' => 'Integrated Bar of the Philippines',
            'practice_areas' => ['contracts'],
            'region' => 'ncr',
            'id_document' => UploadedFile::fake()->createWithContent('id.pdf', 'id-bytes'),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['bar_membership_document']);
});

it('requires notarial commission details when registering as a notary', function () {
    $user = User::factory()->create();

    $this->signInAs($user)
        ->postJson('/api/lawyer/profile', [
            'full_name' => 'Atty. Maria Santos',
            'bar_number' => '12345',
            'bar_jurisdiction' => 'Integrated Bar of the Philippines',
            'practice_areas' => ['contracts'],
            'region' => 'ncr',
            'is_notary' => true,
            'id_document' => UploadedFile::fake()->createWithContent('id.pdf', 'id-bytes'),
            'bar_membership_document' => UploadedFile::fake()->createWithContent('bar.pdf', 'bar-bytes'),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['notarial_commission_number']);
});

it('approves a pending lawyer and notifies them', function () {
    Notification::fake();

    $lawyer = User::factory()->create();
    $profile = LawyerProfile::factory()->for($lawyer)->create();

    $admin = User::factory()->admin()->create();

    $this->signInAs($admin)
        ->postJson("/api/admin/lawyers/{$profile->id}/approve")
        ->assertOk()
        ->assertJsonPath('data.verification_status', 'verified');

    expect($profile->fresh()->verification_status)->toBe(LawyerVerificationStatus::Verified)
        ->and($profile->fresh()->verification_reviewed_at)->not->toBeNull()
        ->and($profile->fresh()->verified_at)->not->toBeNull();

    Notification::assertSentTo($lawyer, LawyerVerificationResult::class);
});

it('rejects a pending lawyer with a reason', function () {
    Notification::fake();

    $lawyer = User::factory()->create();
    $profile = LawyerProfile::factory()->for($lawyer)->create();

    $admin = User::factory()->admin()->create();

    $this->signInAs($admin)
        ->postJson("/api/admin/lawyers/{$profile->id}/reject", [
            'reason' => 'The bar membership file is illegible.',
        ])
        ->assertOk()
        ->assertJsonPath('data.verification_status', 'rejected')
        ->assertJsonPath('data.verification_reason', 'The bar membership file is illegible.');

    Notification::assertSentTo($lawyer, LawyerVerificationResult::class);
});

it('reopens a rejected profile for resubmission', function () {
    $lawyer = User::factory()->create();
    $profile = LawyerProfile::factory()->for($lawyer)->rejected()->create();

    $admin = User::factory()->admin()->create();

    $this->signInAs($admin)
        ->postJson("/api/admin/lawyers/{$profile->id}/reopen")
        ->assertOk()
        ->assertJsonPath('data.verification_status', 'pending');

    expect($profile->fresh()->verification_reason)->toBeNull();
});

it('refuses verification actions to non-admins', function () {
    $lawyer = User::factory()->create();
    $profile = LawyerProfile::factory()->for($lawyer)->create();

    $regular = User::factory()->create();

    $this->signInAs($regular)
        ->postJson("/api/admin/lawyers/{$profile->id}/approve")
        ->assertForbidden();
});

it('serves a credential document to a reviewing admin', function () {
    $lawyer = User::factory()->create();
    $profile = LawyerProfile::factory()->for($lawyer)->create([
        'id_document_path' => 'lawyer-documents/id.pdf',
        'bar_membership_document_path' => 'lawyer-documents/bar.pdf',
    ]);

    Storage::disk('local')->put('lawyer-documents/id.pdf', 'id-bytes');
    Storage::disk('local')->put('lawyer-documents/bar.pdf', 'bar-bytes');

    $admin = User::factory()->admin()->create();

    $response = $this->signInAs($admin)
        ->get("/api/admin/lawyers/{$profile->id}/document/id_document")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    $this->assertStringContainsString('inline', $response->headers->get('Content-Disposition'));
});

it('hides credential documents from everyone else', function () {
    $lawyer = User::factory()->create();
    $profile = LawyerProfile::factory()->for($lawyer)->create([
        'id_document_path' => 'lawyer-documents/id.pdf',
    ]);

    Storage::disk('local')->put('lawyer-documents/id.pdf', 'id-bytes');

    $this->signInAs($lawyer)
        ->getJson("/api/admin/lawyers/{$profile->id}/document/id_document")
        ->assertForbidden();
});

it('toggles availability only for verified lawyers', function () {
    $lawyer = User::factory()->create();
    $profile = LawyerProfile::factory()->for($lawyer)->create();

    $this->signInAs($lawyer)
        ->patchJson('/api/lawyer/profile/availability', ['available' => true])
        ->assertUnprocessable();

    $profile->update(['verification_status' => LawyerVerificationStatus::Verified]);

    $this->signInAs($lawyer)
        ->patchJson('/api/lawyer/profile/availability', ['available' => true])
        ->assertOk()
        ->assertJsonPath('data.available', true);
});

it('updates notification preferences without re-verifying', function () {
    $lawyer = User::factory()->create();
    LawyerProfile::factory()->for($lawyer)->verified()->create();

    $this->signInAs($lawyer)
        ->patchJson('/api/lawyer/profile', [
            'notify_email' => false,
            'notify_push' => true,
            'city' => 'Quezon City',
        ])
        ->assertOk()
        ->assertJsonPath('data.notify_email', false)
        ->assertJsonPath('data.notify_push', true);

    expect($lawyer->lawyerProfile->fresh()->verification_status)->toBe(LawyerVerificationStatus::Verified)
        ->and($lawyer->lawyerProfile->fresh()->profile_changed_at)->toBeNull();
});

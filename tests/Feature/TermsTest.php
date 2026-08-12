<?php

use App\Models\LegalDocument;
use App\Models\User;
use App\Models\UserAcceptance;
use Database\Seeders\LegalDocumentSeeder;

beforeEach(function () {
    LegalDocument::forgetCurrent();
});

function publishTerms(string $version = '1.1', string $content = 'Terms body'): LegalDocument
{
    LegalDocument::forgetCurrent();

    return LegalDocument::create([
        'type' => LegalDocument::TYPE_TERMS_PRIVACY,
        'version' => $version,
        'title' => 'Terms of Service and Privacy Policy',
        'content' => $content,
        'effective_at' => now()->subDay(),
    ]);
}

test('the seeder publishes a terms document that the endpoint can serve', function () {
    $this->seed(LegalDocumentSeeder::class);
    LegalDocument::forgetCurrent();

    $response = $this->signInAs(User::factory()->create())->getJson('/api/terms/document');

    $response->assertOk()
        ->assertJsonStructure(['title', 'content', 'hash', 'version', 'effective_at']);

    expect($response->json('content'))->not->toBeEmpty()
        ->and($response->json('content'))->toContain('Batayan is an artificial intelligence tool')
        ->and($response->json('content'))->toContain('is not a licensed attorney')
        ->and($response->json('hash'))->toBe(hash('sha256', $response->json('content')));
});

test('the seeder is idempotent', function () {
    $this->seed(LegalDocumentSeeder::class);
    $this->seed(LegalDocumentSeeder::class);

    expect(LegalDocument::count())->toBe(1);
});

test('the document endpoint reports unavailable when nothing is published', function () {
    $this->signInAs(User::factory()->create())
        ->getJson('/api/terms/document')
        ->assertStatus(503);
});

test('accepting the terms records the version and hash that was shown', function () {
    $document = publishTerms();
    $user = User::factory()->create(['terms_accepted_at' => null, 'terms_version' => null]);

    $this->signInAs($user)
        ->postJson('/api/terms/accept', ['marketing_opt_in' => true])
        ->assertOk()
        ->assertJson(['success' => true, 'version' => $document->version]);

    $acceptance = UserAcceptance::where('user_id', $user->id)->sole();

    expect($acceptance->document_version)->toBe($document->version)
        ->and($acceptance->document_hash)->toBe($document->hash)
        ->and($acceptance->marketing_opt_in)->toBeTrue();

    expect($user->fresh()->terms_version)->toBe($document->version)
        ->and($user->fresh()->terms_accepted_at)->not->toBeNull();
});

test('a user who accepted an older version must accept again', function () {
    publishTerms('1.0');
    $user = User::factory()->create(['terms_accepted_at' => now(), 'terms_version' => '1.0']);

    expect($user->hasAcceptedTerms())->toBeTrue();

    // Publish a newer version.
    publishTerms('1.1');

    expect($user->fresh()->hasAcceptedTerms())->toBeFalse();

    $this->signInAs($user)->getJson('/api/terms/status')
        ->assertOk()
        ->assertJson([
            'accepted' => false,
            'needs_reacceptance' => true,
            'accepted_version' => '1.0',
            'current_version' => '1.1',
        ]);
});

test('a document that is not yet effective is not served', function () {
    LegalDocument::create([
        'type' => LegalDocument::TYPE_TERMS_PRIVACY,
        'version' => '2.0',
        'title' => 'Future terms',
        'content' => 'Not yet',
        'effective_at' => now()->addWeek(),
    ]);
    publishTerms('1.1');

    $this->signInAs(User::factory()->create())
        ->getJson('/api/terms/document')
        ->assertOk()
        ->assertJson(['version' => '1.1']);
});

test('the user payload exposes acceptance state for the frontend', function () {
    publishTerms('1.1');
    $user = User::factory()->create(['terms_accepted_at' => now(), 'terms_version' => '1.0']);

    $this->signInAs($user)->getJson('/api/user')
        ->assertOk()
        ->assertJson(['data' => [
            'terms_accepted' => false,
            'terms_current_version' => '1.1',
            'terms_version' => '1.0',
        ]]);
});

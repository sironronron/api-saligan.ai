<?php

use App\Enums\LabelKind;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\Label;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Crawler\LegalDigestService;

beforeEach(function () {
    $this->user = User::factory()->create();
    Subscription::factory()->for($this->user)->create([
        'plan_id' => Plan::factory()->pro()->create()->id,
    ]);
});

it('requires authentication', function () {
    $document = Document::factory()->for($this->user)->create();

    $this->getJson("/api/documents/{$document->id}/content")->assertStatus(401);
});

it('returns the extracted text in citation order with its filing detail', function () {
    $document = Document::factory()->for($this->user)->create([
        'title' => 'Deed of Sale',
        'original_filename' => 'deed-of-sale.pdf',
        'mime_type' => 'application/pdf',
        'digest' => 'A deed transferring the Quezon City lot.',
    ]);

    $label = Label::factory()->create(['kind' => LabelKind::DocumentCategory, 'name' => 'Documentary evidence']);
    $document->labels()->attach($label);

    DocumentChunk::factory()->for($document)->for($this->user)->create([
        'chunk_index' => 1,
        'content' => 'Second paragraph.',
    ]);
    DocumentChunk::factory()->for($document)->for($this->user)->create([
        'chunk_index' => 0,
        'content' => 'First paragraph.',
    ]);

    $response = $this->signInAs($this->user)
        ->getJson("/api/documents/{$document->id}/content")
        ->assertOk();

    expect($response->json('data.title'))->toBe('Deed of Sale')
        ->and($response->json('data.original_filename'))->toBe('deed-of-sale.pdf')
        ->and($response->json('data.has_digest'))->toBeTrue()
        ->and($response->json('data.digest'))->toContain('Quezon City')
        ->and($response->json('data.categories.0.name'))->toBe('Documentary evidence')
        ->and($response->json('data.uploaded_at'))->not->toBeNull()
        ->and($response->json('data.chunks.0.index'))->toBe(0)
        ->and($response->json('data.chunks.0.content'))->toBe('First paragraph.')
        ->and($response->json('data.chunks.1.index'))->toBe(1);
});

it('does not serve another user document', function () {
    $document = Document::factory()->for(User::factory())->create();

    $this->signInAs($this->user)
        ->getJson("/api/documents/{$document->id}/content")
        ->assertForbidden();
});

it('digests a document the first time its citation is opened', function () {
    $this->mock(LegalDigestService::class)
        ->shouldReceive('generate')
        ->once()
        ->andReturn('Summary of the contract.');

    $document = Document::factory()->for($this->user)->create(['digest' => null]);
    DocumentChunk::factory()->for($document)->for($this->user)->create([
        'chunk_index' => 0,
        'content' => 'The parties agree as follows.',
    ]);

    $response = $this->signInAs($this->user)
        ->getJson("/api/documents/{$document->id}/content")
        ->assertOk();

    expect($response->json('data.digest'))->toBe('Summary of the contract.')
        ->and($response->json('data.has_digest'))->toBeTrue()
        ->and($document->fresh()->digest)->toBe('Summary of the contract.')
        ->and($document->fresh()->digest_generated_at)->not->toBeNull();
});

it('leaves a document without extractable text undigested', function () {
    $this->mock(LegalDigestService::class)->shouldNotReceive('generate');

    $document = Document::factory()->for($this->user)->create(['digest' => null]);

    $response = $this->signInAs($this->user)
        ->getJson("/api/documents/{$document->id}/content")
        ->assertOk();

    expect($response->json('data.has_digest'))->toBeFalse()
        ->and($response->json('data.chunks'))->toBe([]);
});

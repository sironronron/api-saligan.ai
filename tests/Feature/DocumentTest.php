<?php

use App\Enums\DocumentStatus;
use App\Jobs\ProcessDocumentUpload;
use App\Models\Document;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('requires authentication', function () {
    $this->getJson('/api/documents')->assertStatus(401);
});

it('lists only the authenticated user documents', function () {
    $own = Document::factory()->for($this->user)->create();
    Document::factory()->for(User::factory())->create();

    $response = $this->actingAs($this->user)
        ->getJson('/api/documents')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.id'))->toBe($own->id);
});

it('stores an uploaded document and queues ingestion', function () {
    Queue::fake();
    Storage::fake('local');

    $response = $this->actingAs($this->user)
        ->postJson('/api/documents', [
            'file' => UploadedFile::fake()->createWithContent('memo.txt', 'Plain legal notes.'),
            'title' => 'My memo',
        ])
        ->assertCreated();

    expect($response->json('data.title'))->toBe('My memo')
        ->and($response->json('data.status'))->toBe(DocumentStatus::Queued->value);

    $this->assertDatabaseHas('documents', [
        'user_id' => $this->user->id,
        'status' => DocumentStatus::Queued->value,
    ]);

    Queue::assertPushed(ProcessDocumentUpload::class);
});

it('rejects unsupported file types', function () {
    Queue::fake();

    $this->actingAs($this->user)
        ->postJson('/api/documents', [
            'file' => UploadedFile::fake()->createWithContent('malware.exe', 'x'),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file');

    Queue::assertNotPushed(ProcessDocumentUpload::class);
});

it('shows a single document with chunk count', function () {
    $document = Document::factory()->for($this->user)->create();

    $this->actingAs($this->user)
        ->getJson("/api/documents/{$document->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $document->id);
});

it('forbids showing another user document', function () {
    $document = Document::factory()->for(User::factory())->create();

    $this->actingAs($this->user)
        ->getJson("/api/documents/{$document->id}")
        ->assertForbidden();
});

it('deletes a document and its stored file', function () {
    Storage::fake('local');

    $document = Document::factory()->for($this->user)->create([
        'storage_path' => 'documents/memo.pdf',
    ]);

    Storage::put('documents/memo.pdf', 'pdf');

    $this->actingAs($this->user)
        ->deleteJson("/api/documents/{$document->id}")
        ->assertNoContent();

    $this->assertDatabaseMissing('documents', ['id' => $document->id]);
    Storage::assertMissing('documents/memo.pdf');
});

it('forbids deleting another user document', function () {
    $document = Document::factory()->for(User::factory())->create();

    $this->actingAs($this->user)
        ->deleteJson("/api/documents/{$document->id}")
        ->assertForbidden();
});

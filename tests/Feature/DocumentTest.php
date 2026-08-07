<?php

use App\Enums\DocumentStatus;
use App\Jobs\ProcessDocumentUpload;
use App\Models\Document;
use App\Models\LegalCase;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->user = User::factory()->create();
    Subscription::factory()->for($this->user)->create([
        'plan_id' => Plan::factory()->pro()->create()->id,
    ]);
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

it('accepts image uploads for OCR processing', function () {
    Queue::fake();
    Storage::fake('local');

    $response = $this->actingAs($this->user)
        ->postJson('/api/documents', [
            'file' => UploadedFile::fake()->image('scan.png'),
        ])
        ->assertCreated();

    expect($response->json('data.mime_type'))->toBe('image/png')
        ->and($response->json('data.status'))->toBe(DocumentStatus::Queued->value);

    $this->assertDatabaseHas('documents', [
        'user_id' => $this->user->id,
        'mime_type' => 'image/png',
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

it('lists documents scoped to a case', function () {
    $case = LegalCase::factory()->for($this->user)->create();
    $otherCase = LegalCase::factory()->for($this->user)->create();

    $inCase = Document::factory()->for($this->user)->create(['case_id' => $case->id]);
    $inOtherCase = Document::factory()->for($this->user)->create(['case_id' => $otherCase->id]);
    Document::factory()->for($this->user)->create(['case_id' => null]);

    $response = $this->actingAs($this->user)
        ->getJson("/api/documents?case_id={$case->id}")
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.id'))->toBe($inCase->id)
        ->and($response->json('data.0.case_id'))->toBe($case->id);
});

it('forbids listing documents for another user case', function () {
    $case = LegalCase::factory()->for(User::factory())->create();

    $this->actingAs($this->user)
        ->getJson("/api/documents?case_id={$case->id}")
        ->assertForbidden();
});

it('stores an uploaded document attached to a case', function () {
    Queue::fake();
    Storage::fake('local');

    $case = LegalCase::factory()->for($this->user)->create();

    $response = $this->actingAs($this->user)
        ->postJson('/api/documents', [
            'file' => UploadedFile::fake()->createWithContent('complaint.txt', 'Complaint notes.'),
            'case_id' => $case->id,
        ])
        ->assertCreated();

    expect($response->json('data.case_id'))->toBe($case->id);

    $this->assertDatabaseHas('documents', [
        'user_id' => $this->user->id,
        'case_id' => $case->id,
    ]);

    Queue::assertPushed(ProcessDocumentUpload::class);
});

it('forbids uploading a document into another user case', function () {
    Queue::fake();

    $case = LegalCase::factory()->for(User::factory())->create();

    $this->actingAs($this->user)
        ->postJson('/api/documents', [
            'file' => UploadedFile::fake()->createWithContent('notes.txt', 'x'),
            'case_id' => $case->id,
        ])
        ->assertForbidden();

    Queue::assertNotPushed(ProcessDocumentUpload::class);
});

it('attaches an existing document to one of the users cases', function () {
    $case = LegalCase::factory()->for($this->user)->create();
    $document = Document::factory()->for($this->user)->create(['case_id' => null]);

    $response = $this->actingAs($this->user)
        ->postJson("/api/documents/{$document->id}/attach", ['case_id' => $case->id])
        ->assertOk();

    expect($response->json('data.case_id'))->toBe($case->id);

    $this->assertDatabaseHas('documents', [
        'id' => $document->id,
        'case_id' => $case->id,
    ]);
});

it('requires a case when attaching a document', function () {
    $document = Document::factory()->for($this->user)->create();

    $this->actingAs($this->user)
        ->postJson("/api/documents/{$document->id}/attach", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('case_id');
});

it('forbids attaching another user document', function () {
    $case = LegalCase::factory()->for($this->user)->create();
    $document = Document::factory()->for(User::factory())->create();

    $this->actingAs($this->user)
        ->postJson("/api/documents/{$document->id}/attach", ['case_id' => $case->id])
        ->assertForbidden();
});

it('forbids attaching a document to another user case', function () {
    $document = Document::factory()->for($this->user)->create();
    $case = LegalCase::factory()->for(User::factory())->create();

    $this->actingAs($this->user)
        ->postJson("/api/documents/{$document->id}/attach", ['case_id' => $case->id])
        ->assertForbidden();
});

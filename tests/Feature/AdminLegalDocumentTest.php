<?php

use App\Enums\CrawlStatus;
use App\Enums\LegalSourceCategory;
use App\Jobs\ProcessLegalDocumentUpload;
use App\Models\CrawledPage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->admin = User::factory()->admin()->create();
});

it('rejects non-admin users from legal document management', function () {
    $this->signInAs($this->user)
        ->getJson('/api/admin/legal-documents')
        ->assertForbidden();

    $this->signInAs($this->user)
        ->postJson('/api/admin/legal-documents', [
            'category' => LegalSourceCategory::Jurisprudence->value,
        ])->assertForbidden();
});

it('lists uploaded legal documents with chunk counts', function () {
    $uploaded = CrawledPage::factory()->uploaded()->create();
    CrawledPage::factory()->create();

    $response = $this->signInAs($this->admin)
        ->getJson('/api/admin/legal-documents')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.id'))->toBe($uploaded->id)
        ->and($response->json('data.0.kind'))->toBe(CrawledPage::KIND_UPLOADED);
});

it('stores an uploaded legal document and queues its ingestion', function () {
    Queue::fake();
    Storage::fake('local');

    $response = $this->signInAs($this->admin)
        ->postJson('/api/admin/legal-documents', [
            'file' => UploadedFile::fake()->createWithContent('decision.pdf', '%PDF-1.4 fake bytes'),
            'title' => 'People v. Juan',
            'law_name' => 'G.R. No. 143491',
            'gr_number' => 'G.R. No. 143491',
            'promulgation_date' => '2003-06-10',
            'category' => LegalSourceCategory::Jurisprudence->value,
        ])->assertCreated();

    $page = CrawledPage::findOrFail($response->json('id'));

    expect($page->kind)->toBe(CrawledPage::KIND_UPLOADED)
        ->and($page->category)->toBe(LegalSourceCategory::Jurisprudence)
        ->and($page->title)->toBe('People v. Juan')
        ->and($page->law_name)->toBe('G.R. No. 143491')
        ->and($page->promulgation_date?->toDateString())->toBe('2003-06-10')
        ->and($page->original_filename)->toBe('decision.pdf')
        ->and($page->mime_type)->toBe('application/pdf')
        ->and($page->crawl_status)->toBe(CrawlStatus::Pending)
        ->and($page->legal_source_id)->toBeNull()
        ->and($page->url)->toBeNull();

    expect(Storage::disk('local')->exists($page->storage_path))->toBeTrue();

    Queue::assertPushed(ProcessLegalDocumentUpload::class);
});

it('requires a valid category and a supported file type', function () {
    $this->signInAs($this->admin)
        ->postJson('/api/admin/legal-documents', [
            'file' => UploadedFile::fake()->createWithContent('memo.txt', 'Plain text.'),
            'category' => 'not-a-category',
        ])->assertUnprocessable()
        ->assertJsonValidationErrors(['category']);

    $this->signInAs($this->admin)
        ->postJson('/api/admin/legal-documents', [
            'file' => UploadedFile::fake()->create('virus.exe'),
            'category' => LegalSourceCategory::Law->value,
        ])->assertUnprocessable()
        ->assertJsonValidationErrors(['file']);
});

it('serves the original file of an uploaded document', function () {
    Storage::fake('local');

    $page = CrawledPage::factory()->uploaded()->create([
        'storage_path' => 'legal-uploads/sample.pdf',
        'original_filename' => 'decision.pdf',
        'mime_type' => 'application/pdf',
    ]);

    Storage::disk('local')->put('legal-uploads/sample.pdf', '%PDF-1.4 fake bytes');

    $this->signInAs($this->admin)
        ->getJson("/api/admin/legal-documents/{$page->id}/file")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Disposition', 'inline; filename=decision.pdf');
});

it('deletes an uploaded document, its file, and its chunks', function () {
    Storage::fake('local');

    $page = CrawledPage::factory()->uploaded()->create([
        'storage_path' => 'legal-uploads/gone.pdf',
    ]);

    Storage::disk('local')->put('legal-uploads/gone.pdf', '%PDF-1.4 fake bytes');

    $this->signInAs($this->admin)
        ->deleteJson("/api/admin/legal-documents/{$page->id}")
        ->assertNoContent();

    $this->assertDatabaseMissing('crawled_pages', ['id' => $page->id]);
    expect(Storage::disk('local')->exists('legal-uploads/gone.pdf'))->toBeFalse();
});

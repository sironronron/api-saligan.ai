<?php

use App\Ai\DocumentCategoryAgent;
use App\Enums\DocumentStatus;
use App\Enums\LabelKind;
use App\Jobs\ProcessDocumentUpload;
use App\Models\Document;
use App\Models\Label;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\EmbeddingService;
use App\Services\Documents\DocumentChunker;
use App\Services\Documents\DocumentClassifier;
use App\Services\Documents\DocumentEncryptor;
use App\Services\Documents\ImageOcrExtractor;
use App\Services\Documents\TextExtractor;
use Database\Seeders\LabelSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    (new LabelSeeder)->run();

    config(['saligan.documents.classification.enabled' => true]);

    $this->user = User::factory()->create();

    $this->document = Document::factory()->for($this->user)->create([
        'original_filename' => 'judicial-affidavit-cruz.pdf',
        'title' => 'Judicial Affidavit of Cruz',
    ]);

    $this->excerpt = 'JUDICIAL AFFIDAVIT of JUAN CRUZ, of legal age, Filipino, after having been duly sworn...';

    $this->classify = fn (?Document $document = null) => app(DocumentClassifier::class)
        ->classify($document ?? $this->document, $this->excerpt);
});

it('files a document under the categories the model returns', function () {
    DocumentCategoryAgent::fake([
        ['categories' => [['slug' => 'evidence-testimonial', 'confidence' => 0.94]]],
    ]);

    ($this->classify)();

    $labels = $this->document->fresh()->labels;

    expect($labels->pluck('slug')->all())->toBe(['evidence-testimonial'])
        ->and($labels->first()->pivot->source)->toBe('ai')
        ->and((float) $labels->first()->pivot->confidence)->toBe(0.94)
        ->and($labels->first()->pivot->assigned_by)->toBeNull();
});

it('files a document under every category it genuinely belongs to', function () {
    DocumentCategoryAgent::fake([
        ['categories' => [
            ['slug' => 'pleading', 'confidence' => 0.91],
            ['slug' => 'procedural-compliance', 'confidence' => 0.78],
        ]],
    ]);

    ($this->classify)();

    expect($this->document->fresh()->labels->pluck('slug')->sort()->values()->all())
        ->toBe(['pleading', 'procedural-compliance']);
});

it('drops a category the model is not confident enough about', function () {
    DocumentCategoryAgent::fake([
        ['categories' => [
            ['slug' => 'evidence-testimonial', 'confidence' => 0.88],
            ['slug' => 'correspondence', 'confidence' => 0.21],
        ]],
    ]);

    ($this->classify)();

    expect($this->document->fresh()->labels->pluck('slug')->all())->toBe(['evidence-testimonial']);
});

it('leaves a document unfiled when the model cannot place it', function () {
    DocumentCategoryAgent::fake([['categories' => []]]);

    ($this->classify)();

    expect($this->document->fresh()->labels)->toHaveCount(0);
});

it('never overwrites a filing a person made', function () {
    $pleading = Label::where('kind', LabelKind::DocumentCategory)->where('slug', 'pleading')->firstOrFail();
    $this->document->syncLabels([$pleading], $this->user);

    DocumentCategoryAgent::fake([
        ['categories' => [['slug' => 'evidence-testimonial', 'confidence' => 0.99]]],
    ]);

    ($this->classify)();

    expect($this->document->fresh()->labels->pluck('slug')->all())->toBe(['pleading']);
    DocumentCategoryAgent::assertNeverPrompted();
});

it('keeps only the most confident categories when the model names too many', function () {
    config(['saligan.documents.classification.max_categories' => 2]);

    DocumentCategoryAgent::fake([
        ['categories' => [
            ['slug' => 'pleading', 'confidence' => 0.71],
            ['slug' => 'evidence-documentary', 'confidence' => 0.95],
            ['slug' => 'correspondence', 'confidence' => 0.83],
        ]],
    ]);

    ($this->classify)();

    expect($this->document->fresh()->labels->pluck('slug')->sort()->values()->all())
        ->toBe(['correspondence', 'evidence-documentary']);
});

it('ignores a category that is not in the vocabulary', function () {
    DocumentCategoryAgent::fake([
        ['categories' => [
            ['slug' => 'invented-category', 'confidence' => 0.99],
            ['slug' => 'pleading', 'confidence' => 0.81],
        ]],
    ]);

    ($this->classify)();

    expect($this->document->fresh()->labels->pluck('slug')->all())->toBe(['pleading']);
});

it('can file under a category the firm added itself', function () {
    $organization = Organization::factory()->create();
    $member = User::factory()->memberOf($organization)->create();
    $document = Document::factory()->for($member)->create();

    Label::factory()->forOrganization($organization, $member)->create([
        'slug' => 'barangay-conciliation',
        'name' => 'Barangay Conciliation',
    ]);

    DocumentCategoryAgent::fake([
        ['categories' => [['slug' => 'barangay-conciliation', 'confidence' => 0.9]]],
    ]);

    ($this->classify)($document);

    expect($document->fresh()->labels->pluck('slug')->all())->toBe(['barangay-conciliation']);
});

it('cannot file under a category belonging to another firm', function () {
    Label::factory()->forOrganization(Organization::factory()->create())->create([
        'slug' => 'someone-elses-category',
        'name' => "Someone Else's Category",
    ]);

    DocumentCategoryAgent::fake([
        ['categories' => [['slug' => 'someone-elses-category', 'confidence' => 0.99]]],
    ]);

    ($this->classify)();

    expect($this->document->fresh()->labels)->toHaveCount(0);
});

it('does not reach a model when classification is switched off', function () {
    config(['saligan.documents.classification.enabled' => false]);

    DocumentCategoryAgent::fake([
        ['categories' => [['slug' => 'pleading', 'confidence' => 0.99]]],
    ]);

    ($this->classify)();

    expect($this->document->fresh()->labels)->toHaveCount(0);
    DocumentCategoryAgent::assertNeverPrompted();
});

it('leaves the document unfiled rather than failing when the model errors', function () {
    DocumentCategoryAgent::fake(function (): never {
        throw new RuntimeException('the provider is down');
    });

    ($this->classify)();

    expect($this->document->fresh()->labels)->toHaveCount(0);
});

it('files an uploaded document as part of ingestion', function () {
    Storage::fake('local');
    Http::fake([
        '*/api/embed' => fn (Request $request) => Http::response([
            'embeddings' => array_map(
                fn () => array_fill(0, 768, 0.5),
                $request->data()['input'] ?? [],
            ),
        ], 200),
    ]);

    DocumentCategoryAgent::fake([
        ['categories' => [['slug' => 'evidence-testimonial', 'confidence' => 0.93]]],
    ]);

    Storage::put('documents/affidavit.txt', implode("\n\n", array_fill(0, 20, $this->excerpt)));

    $document = Document::factory()->for($this->user)->create([
        'storage_path' => 'documents/affidavit.txt',
        'mime_type' => 'text/plain',
        'status' => DocumentStatus::Queued,
    ]);

    (new ProcessDocumentUpload($document))->handle(
        app(TextExtractor::class),
        app(ImageOcrExtractor::class),
        app(DocumentChunker::class),
        app(EmbeddingService::class),
        app(DocumentEncryptor::class),
        app(DocumentClassifier::class),
    );

    expect($document->fresh()->status)->toBe(DocumentStatus::Ready)
        ->and($document->fresh()->labels->pluck('slug')->all())->toBe(['evidence-testimonial']);
});

it('still finishes ingestion when classification fails', function () {
    Storage::fake('local');
    Http::fake([
        '*/api/embed' => fn (Request $request) => Http::response([
            'embeddings' => array_map(
                fn () => array_fill(0, 768, 0.5),
                $request->data()['input'] ?? [],
            ),
        ], 200),
    ]);

    DocumentCategoryAgent::fake(function (): never {
        throw new RuntimeException('the provider is down');
    });

    Storage::put('documents/affidavit.txt', implode("\n\n", array_fill(0, 20, $this->excerpt)));

    $document = Document::factory()->for($this->user)->create([
        'storage_path' => 'documents/affidavit.txt',
        'mime_type' => 'text/plain',
        'status' => DocumentStatus::Queued,
    ]);

    (new ProcessDocumentUpload($document))->handle(
        app(TextExtractor::class),
        app(ImageOcrExtractor::class),
        app(DocumentChunker::class),
        app(EmbeddingService::class),
        app(DocumentEncryptor::class),
        app(DocumentClassifier::class),
    );

    expect($document->fresh()->status)->toBe(DocumentStatus::Ready)
        ->and($document->fresh()->chunks()->count())->toBeGreaterThan(0)
        ->and($document->fresh()->labels)->toHaveCount(0);
});

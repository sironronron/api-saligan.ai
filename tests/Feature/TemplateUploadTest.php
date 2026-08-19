<?php

use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Template;
use App\Services\Documents\DocumentEncryptor;
use App\Services\Documents\StoredFiles;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use ZipArchive;

beforeEach(function () {
    Storage::fake('local');

    $this->organization = Organization::factory()->create(['name' => 'Acme Law Office']);
    $this->owner = User::factory()->ownerOf($this->organization)->create();
    $this->plan = Plan::factory()->pro()->create();
    Subscription::factory()->for($this->organization)->for($this->owner)->create([
        'plan_id' => $this->plan->id,
    ]);
});

/**
 * A document.xml string wrapping the given body paragraphs.
 */
function documentXmlWithBody(string $body): string
{
    $ns = implode(' ', [
        'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"',
        'xmlns:wpc="http://schemas.microsoft.com/office/word/2010/wordprocessingCanvas"',
        'xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006"',
        'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"',
    ]);

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        ."<w:document {$ns}>"
        .'<w:body>'.$body
        .'<w:sectPr><w:pgSz w:w="12240" w:h="15840"/></w:sectPr>'
        .'</w:body></w:document>';
}

/**
 * A paragraph with the given runs, each [text, rPr|null].
 */
function paragraphXml(array $runs): string
{
    $xml = '';

    foreach ($runs as [$text, $rpr]) {
        $xml .= '<w:r>';
        $xml .= $rpr !== null ? '<w:rPr>'.$rpr.'</w:rPr>' : '';
        $xml .= '<w:t xml:space="preserve">'.$text.'</w:t></w:r>';
    }

    return '<w:p>'.$xml.'</w:p>';
}

/**
 * Build a real .docx file whose word/document.xml carries the given body.
 */
function buildDocxWithBody(string $body): string
{
    $phpWord = new PhpWord;
    $section = $phpWord->addSection();
    $section->addText('base');

    $path = tempnam(sys_get_temp_dir(), 'tpl_').'.docx';
    IOFactory::createWriter($phpWord, 'Word2007')->save($path);

    $zip = new ZipArchive;

    if ($zip->open($path) === true) {
        $zip->addFromString('word/document.xml', documentXmlWithBody($body));
        $zip->close();
    }

    return $path;
}

/**
 * Build an UploadedFile for a docx built by buildDocxWithBody.
 */
function docxUpload(string $path, string $name = 'contract.docx'): UploadedFile
{
    return new UploadedFile(
        $path,
        $name,
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        null,
        true,
    );
}

it('stores an uploaded docx verbatim and keeps the bracketed placeholders', function () {
    $body = paragraphXml([['Dear [Client Name],', null], [' please settle the balance of [Amount Due].', null]]);
    $path = buildDocxWithBody($body);

    $response = $this->signInAs($this->owner)->postJson('/api/templates', [
        'name' => 'Demand Letter Contract',
        'category' => 'legal',
        'template_file' => docxUpload($path),
    ])->assertCreated();

    $template = Template::findOrFail($response->json('data.id'));

    expect($response->json('data.is_docx'))->toBeTrue()
        ->and($response->json('data.is_system'))->toBeFalse();

    expect($template->isDocxFileTemplate())->toBeTrue()
        ->and($template->original_path)->not->toBeNull()
        ->and($template->placeholder_fields)->toBe(['[Client Name]', '[Amount Due]'])
        ->and($template->mime_type)->toBe('application/vnd.openxmlformats-officedocument.wordprocessingml.document');

    // The stored file is byte-for-byte the original upload once decrypted:
    // never converted to markdown or plain text. The bytes on disk are
    // ciphertext, which is the point — a template carries client particulars
    // like any other upload.
    expect(app(DocumentEncryptor::class)->isEncrypted($template->original_path))->toBeTrue();

    $copy = app(StoredFiles::class)->localCopy($template->original_path);

    expect(file_get_contents($copy->path))->toBe(file_get_contents($path));

    $copy->discard();

    @unlink($path);
});

it('detects a placeholder split across runs with identical formatting', function () {
    // Word silently splits "[Client Name]" across two runs after autocorrect.
    $body = paragraphXml([
        ['Dear ', null],
        ['[Client', null],
        [' Name]', null],
        [', please settle.', null],
    ]);
    $path = buildDocxWithBody($body);

    $this->signInAs($this->owner)->postJson('/api/templates', [
        'name' => 'Split Placeholder',
        'template_file' => docxUpload($path),
    ])->assertCreated()
        ->assertJsonPath('data.placeholder_fields', ['[Client Name]']);

    @unlink($path);
});

it('rejects a docx whose placeholder spans a formatting boundary', function () {
    // "[Client" is plain text while "Name]" is bold, so the placeholder can
    // never be matched as one clean token and must be rejected at upload.
    $body = paragraphXml([
        ['[Client ', null],
        ['Name]', '<w:b/>'],
    ]);
    $path = buildDocxWithBody($body);

    $this->signInAs($this->owner)->postJson('/api/templates', [
        'name' => 'Broken Placeholder',
        'template_file' => docxUpload($path),
    ])->assertStatus(422)
        ->assertJsonPath('message', 'These placeholders could not be matched as clean tokens in the document: [Client Name]. Make sure each [Bracketed Text] placeholder sits in one contiguous run of matching formatting, then re-upload.');

    @unlink($path);
});

it('fills a docx in place, preserving untouched document parts', function () {
    $body = paragraphXml([
        ['Dear [Client Name],', null],
        ['Please settle the balance of [Amount Due].', null],
    ]);
    $path = buildDocxWithBody($body);

    $templateId = $this->signInAs($this->owner)->postJson('/api/templates', [
        'name' => 'Demand Letter',
        'template_file' => docxUpload($path),
    ])->assertCreated()->json('data.id');

    $response = $this->signInAs($this->owner)->postJson("/api/templates/{$templateId}/fill", [
        'values' => ['[Client Name]' => 'Juan Dela Cruz', '[Amount Due]' => 'PHP 5,000.00'],
    ])->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

    $filled = tempnam(sys_get_temp_dir(), 'filled_').'.docx';
    file_put_contents($filled, $response->streamedContent());

    $zip = new ZipArchive;
    expect($zip->open($filled))->toBeTrue();

    $documentXml = $zip->getFromName('word/document.xml');

    expect($documentXml)->toContain('Juan Dela Cruz')
        ->toContain('PHP 5,000.00')
        ->not->toContain('[Client Name]');

    // Every other part of the original archive is untouched.
    expect($zip->getFromName('word/styles.xml'))->not->toBe(false)
        ->and($zip->getFromName('[Content_Types].xml'))->not->toBe(false);

    $zip->close();
    @unlink($filled);
    @unlink($path);
});

it('blocks filling a template owned by another organization', function () {
    $body = paragraphXml([['[Client Name]', null]]);
    $path = buildDocxWithBody($body);

    $templateId = $this->signInAs($this->owner)->postJson('/api/templates', [
        'name' => 'Private Template',
        'template_file' => docxUpload($path),
    ])->assertCreated()->json('data.id');

    $otherOrg = Organization::factory()->create();
    $otherOwner = User::factory()->ownerOf($otherOrg)->create();
    Subscription::factory()->for($otherOrg)->for($otherOwner)->create([
        'plan_id' => $this->plan->id,
    ]);
    $outsider = User::factory()->memberOf($otherOrg)->create();

    $this->signInAs($outsider)
        ->postJson("/api/templates/{$templateId}/fill", ['values' => ['[Client Name]' => 'Hacker']])
        ->assertStatus(403);

    $this->signInAs($outsider)->getJson('/api/templates')
        ->assertOk()
        ->assertJsonMissing(['id' => $templateId]);

    @unlink($path);
});

it('lets an organization member access and fill an org-owned template', function () {
    $body = paragraphXml([['Dear [Client Name],', null]]);
    $path = buildDocxWithBody($body);

    $templateId = $this->signInAs($this->owner)->postJson('/api/templates', [
        'name' => 'Shared Template',
        'template_file' => docxUpload($path),
    ])->assertCreated()->json('data.id');

    $member = User::factory()->memberOf($this->organization)->create();

    $this->signInAs($member)->getJson('/api/templates')
        ->assertOk()
        ->assertJsonFragment(['id' => $templateId]);

    $this->signInAs($member)->postJson("/api/templates/{$templateId}/fill", [
        'values' => ['[Client Name]' => 'Maria Santos'],
    ])->assertOk();

    @unlink($path);
});

it('does not expose file templates as plain content for rendering', function () {
    $template = Template::factory()->create([
        'user_id' => $this->owner->id,
        'name' => 'File Template',
        'content' => 'extracted text for AI analysis only',
        'original_path' => 'template-files/contract.docx',
        'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ]);

    Storage::put('template-files/contract.docx', 'PK fake content');

    $this->signInAs($this->owner)->getJson('/api/templates')
        ->assertOk()
        ->assertJsonFragment(['id' => $template->id]);
});

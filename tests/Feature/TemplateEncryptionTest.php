<?php

use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Template;
use App\Models\User;
use App\Services\Documents\DocumentEncryptor;
use App\Services\Documents\StoredFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;

beforeEach(function () {
    Storage::fake('local');

    $this->organization = Organization::factory()->create();
    $this->owner = User::factory()->ownerOf($this->organization)->create();

    Subscription::factory()->for($this->organization)->for($this->owner)->create([
        'plan_id' => Plan::factory()->pro()->create()->id,
    ]);
});

function templateDocx(): UploadedFile
{
    $word = new PhpWord;
    $section = $word->addSection();
    $section->addText('Sworn statement of [Affiant Name], of legal age.');

    $path = tempnam(sys_get_temp_dir(), 'tpl').'.docx';
    IOFactory::createWriter($word, 'Word2007')->save($path);

    return new UploadedFile(
        $path,
        'affidavit.docx',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        null,
        true,
    );
}

it('stores an uploaded template encrypted at rest', function () {
    $id = $this->signInAs($this->owner)->postJson('/api/templates', [
        'name' => 'Affidavit',
        'template_file' => templateDocx(),
    ])->assertCreated()->json('data.id');

    $path = Template::findOrFail($id)->original_path;

    // The bytes on disk must not be the document. A template carries the same
    // client particulars as any other upload, so it gets the same protection.
    expect(app(DocumentEncryptor::class)->isEncrypted($path))->toBeTrue()
        ->and(Storage::get($path))->not->toContain('Affiant Name');
});

it('still reads a template stored before templates were encrypted', function () {
    // Templates uploaded before this change sit on disk as plaintext, and no
    // migration rewrites them. `localCopy` is what makes that safe: it
    // decrypts an encrypted file and hands a plaintext one back untouched, so
    // an existing library keeps opening either way.
    $upload = templateDocx();
    $original = file_get_contents($upload->getRealPath());

    Storage::put('template-files/legacy.docx', $original);

    $copy = app(StoredFiles::class)->localCopy('template-files/legacy.docx');

    expect(app(DocumentEncryptor::class)->isEncrypted('template-files/legacy.docx'))->toBeFalse()
        ->and(file_get_contents($copy->path))->toBe($original);

    $copy->discard();
});

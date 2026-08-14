<?php

use App\Models\Document;
use App\Services\Documents\DocumentEncryptor;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

/**
 * A file in the legacy v1 layout — no authentication tag, data key used
 * directly as the AES key — plus the Document row that points at it.
 */
function legacyDocument(string $path, string $content): Document
{
    $dataKey = random_bytes(32);
    $baseIv = random_bytes(8);
    $wrapped = app(Encrypter::class)->encryptString($dataKey);

    Storage::put($path, DocumentEncryptor::MAGIC
        .chr(1)
        .pack('n', strlen($wrapped))
        .$wrapped
        .$baseIv
        .openssl_encrypt($content, 'aes-256-ctr', $dataKey, OPENSSL_RAW_DATA, $baseIv.pack('J', 0)));

    return Document::factory()->create(['storage_path' => $path]);
}

it('rewrites legacy files as authenticated ones, preserving their contents', function () {
    $document = legacyDocument('documents/legacy.txt', 'the original contents');

    expect(app(DocumentEncryptor::class)->formatVersion($document->storage_path))->toBe(1);

    $this->artisan('saligan:reencrypt-documents')->assertSuccessful();

    expect(app(DocumentEncryptor::class)->formatVersion($document->storage_path))->toBe(2);

    $decrypted = app(DocumentEncryptor::class)->decryptToTemp($document->storage_path);
    $content = file_get_contents($decrypted);
    @unlink($decrypted);

    expect($content)->toBe('the original contents');
});

it('leaves already-authenticated files alone', function () {
    $source = tempnam(sys_get_temp_dir(), 'enc-');
    file_put_contents($source, 'already authenticated');
    app(DocumentEncryptor::class)->encrypt($source, 'documents/current.txt');
    @unlink($source);

    $document = Document::factory()->create(['storage_path' => 'documents/current.txt']);

    $before = Storage::get($document->storage_path);

    $this->artisan('saligan:reencrypt-documents')
        ->expectsOutputToContain('Skipped: 1')
        ->assertSuccessful();

    // Byte-identical: a needless rewrite would burn a fresh key and IV for no
    // gain, and would make a re-run non-idempotent.
    expect(Storage::get($document->storage_path))->toBe($before);
});

it('reports what it would do without touching anything under --dry-run', function () {
    $document = legacyDocument('documents/untouched.txt', 'still legacy afterwards');

    $before = Storage::get($document->storage_path);

    $this->artisan('saligan:reencrypt-documents', ['--dry-run' => true])->assertSuccessful();

    expect(Storage::get($document->storage_path))->toBe($before)
        ->and(app(DocumentEncryptor::class)->formatVersion($document->storage_path))->toBe(1);
});

it('leaves the original in place when a rewrite fails', function () {
    // A row pointing at a file that is not readable as an encrypted document:
    // the rewrite must fail loudly and leave no staging file behind.
    Storage::put('documents/broken.txt', DocumentEncryptor::MAGIC.chr(1).'truncated');
    Document::factory()->create(['storage_path' => 'documents/broken.txt']);

    $this->artisan('saligan:reencrypt-documents')->assertFailed();

    expect(Storage::exists('documents/broken.txt'))->toBeTrue()
        ->and(Storage::exists('documents/broken.txt.reencrypting'))->toBeFalse();
});

it('skips documents that were never encrypted', function () {
    Storage::put('documents/plain.txt', 'plaintext from before encryption existed');
    Document::factory()->create(['storage_path' => 'documents/plain.txt']);

    $this->artisan('saligan:reencrypt-documents')
        ->expectsOutputToContain('Skipped: 1')
        ->assertSuccessful();

    expect(Storage::get('documents/plain.txt'))->toBe('plaintext from before encryption existed');
});

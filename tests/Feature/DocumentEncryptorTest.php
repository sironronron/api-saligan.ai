<?php

use App\Services\Documents\DocumentEncryptor;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

it('round-trips a payload spanning multiple cipher chunks', function () {
    $original = random_bytes((2 * 1048576) + 500000);

    $source = sys_get_temp_dir().'/encryptor-source.bin';
    file_put_contents($source, $original);

    try {
        app(DocumentEncryptor::class)->encrypt($source, 'documents/large.bin');
    } finally {
        @unlink($source);
    }

    expect(Storage::get('documents/large.bin'))
        ->toStartWith(DocumentEncryptor::MAGIC)
        ->not->toContain(substr($original, 0, 256));

    $decrypted = app(DocumentEncryptor::class)->decryptToTemp('documents/large.bin');

    expect($decrypted)->not->toBeNull();

    $content = file_get_contents($decrypted);
    @unlink($decrypted);

    expect($content)->toBe($original);
});

it('streams decrypted content in the original order', function () {
    $original = random_bytes(1048576 + 4096);

    $source = sys_get_temp_dir().'/encryptor-stream-source.bin';
    file_put_contents($source, $original);

    try {
        app(DocumentEncryptor::class)->encrypt($source, 'documents/stream.bin');
    } finally {
        @unlink($source);
    }

    $rebuilt = '';

    foreach (app(DocumentEncryptor::class)->decryptStream('documents/stream.bin') as $chunk) {
        $rebuilt .= $chunk;
    }

    expect($rebuilt)->toBe($original);
});

it('detects encrypted files and ignores plaintext files', function () {
    Storage::put('documents/plain.txt', 'not encrypted');

    expect(app(DocumentEncryptor::class)->isEncrypted('documents/plain.txt'))->toBeFalse()
        ->and(app(DocumentEncryptor::class)->decryptToTemp('documents/plain.txt'))->toBeNull();

    $source = sys_get_temp_dir().'/encryptor-detect-source.txt';
    file_put_contents($source, 'some secret text');

    try {
        app(DocumentEncryptor::class)->encrypt($source, 'documents/encrypted.txt');
    } finally {
        @unlink($source);
    }

    expect(app(DocumentEncryptor::class)->isEncrypted('documents/encrypted.txt'))->toBeTrue()
        ->and(app(DocumentEncryptor::class)->decryptToTemp('documents/encrypted.txt'))->not->toBeNull();
});

it('rejects a truncated encrypted file', function () {
    $source = sys_get_temp_dir().'/encryptor-truncate-source.txt';
    file_put_contents($source, 'payload that will be truncated');

    try {
        app(DocumentEncryptor::class)->encrypt($source, 'documents/truncated.txt');
    } finally {
        @unlink($source);
    }

    Storage::put('documents/truncated.txt', substr(Storage::get('documents/truncated.txt'), 0, 20));

    expect(fn () => app(DocumentEncryptor::class)->decryptToTemp('documents/truncated.txt'))
        ->toThrow(RuntimeException::class);
});

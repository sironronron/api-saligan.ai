<?php

use App\Services\Documents\DocumentEncryptor;
use Illuminate\Encryption\Encrypter;
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

/**
 * Encrypt `$content` to `$path` and hand back the stored bytes.
 */
function encryptTo(string $path, string $content): string
{
    $source = tempnam(sys_get_temp_dir(), 'enc-');
    file_put_contents($source, $content);

    try {
        app(DocumentEncryptor::class)->encrypt($source, $path);
    } finally {
        @unlink($source);
    }

    return Storage::get($path);
}

/**
 * Write a file in the legacy v1 layout, byte-for-byte as the pre-authentication
 * encryptor produced it: no tag, and the data key used directly as the AES key.
 * Existing installations are full of these, so they have to keep working.
 */
function encryptLegacyV1(string $path, string $content): void
{
    $dataKey = random_bytes(32);
    $baseIv = random_bytes(8);
    $wrapped = app(Encrypter::class)->encryptString($dataKey);

    $ciphertext = openssl_encrypt(
        $content,
        'aes-256-ctr',
        $dataKey,
        OPENSSL_RAW_DATA,
        $baseIv.pack('J', 0),
    );

    Storage::put($path, DocumentEncryptor::MAGIC
        .chr(1)
        .pack('n', strlen($wrapped))
        .$wrapped
        .$baseIv
        .$ciphertext);
}

it('still reads documents written in the legacy unauthenticated format', function () {
    encryptLegacyV1('documents/legacy.txt', 'written before the tag existed');

    expect(app(DocumentEncryptor::class)->formatVersion('documents/legacy.txt'))->toBe(1);

    $decrypted = app(DocumentEncryptor::class)->decryptToTemp('documents/legacy.txt');
    $content = file_get_contents($decrypted);
    @unlink($decrypted);

    expect($content)->toBe('written before the tag existed');
});

it('refuses the legacy format once authentication is required', function () {
    config()->set('saligan.documents.require_authenticated_encryption', true);

    encryptLegacyV1('documents/legacy-refused.txt', 'unauthenticated contents');

    expect(fn () => app(DocumentEncryptor::class)->decryptToTemp('documents/legacy-refused.txt'))
        ->toThrow(RuntimeException::class, 'unauthenticated v1 format');

    // The authenticated format is unaffected by the switch.
    encryptTo('documents/authenticated.txt', 'authenticated contents');

    expect(app(DocumentEncryptor::class)->decryptToTemp('documents/authenticated.txt'))->not->toBeNull();
});

it('detects a single flipped bit in the ciphertext', function () {
    // AES-CTR is a stream cipher: ciphertext bits map one-to-one onto plaintext
    // bits, so without a tag this edit would decrypt cleanly to altered text —
    // an attacker with disk access rewriting a figure in a stored contract, and
    // nothing anywhere noticing.
    $stored = encryptTo('documents/tampered.txt', 'Amount due: PHP 10,000.00');

    // Well past the header, so the byte changed is genuinely ciphertext.
    $target = strlen($stored) - 5;
    $stored[$target] = chr(ord($stored[$target]) ^ 0x01);

    Storage::put('documents/tampered.txt', $stored);

    expect(fn () => app(DocumentEncryptor::class)->decryptToTemp('documents/tampered.txt'))
        ->toThrow(RuntimeException::class, 'failed its integrity check');
});

it('refuses to stream a tampered file rather than emitting partial plaintext', function () {
    $stored = encryptTo('documents/tampered-stream.txt', str_repeat('sensitive contents ', 100));

    $target = strlen($stored) - 5;
    $stored[$target] = chr(ord($stored[$target]) ^ 0xFF);

    Storage::put('documents/tampered-stream.txt', $stored);

    // The throw must happen on the call itself, not on first iteration: a
    // streamed response has already committed its 200 by the time the
    // generator body runs.
    expect(fn () => app(DocumentEncryptor::class)->decryptStream('documents/tampered-stream.txt'))
        ->toThrow(RuntimeException::class, 'failed its integrity check');
});

it('detects a tag swapped in from another file', function () {
    // Both files are valid on their own; the tag of one over the ciphertext of
    // the other must not verify.
    $first = encryptTo('documents/first.txt', 'first document contents');
    $second = encryptTo('documents/second.txt', 'second document contents');

    $tagOffset = strlen($first) - strlen('first document contents') - 32;

    Storage::put('documents/first.txt', substr_replace(
        $first,
        substr($second, $tagOffset, 32),
        $tagOffset,
        32,
    ));

    expect(fn () => app(DocumentEncryptor::class)->decryptToTemp('documents/first.txt'))
        ->toThrow(RuntimeException::class, 'failed its integrity check');
});

it('writes the authenticated format by default', function () {
    encryptTo('documents/versioned.txt', 'contents');

    expect(app(DocumentEncryptor::class)->formatVersion('documents/versioned.txt'))->toBe(2)
        ->and(app(DocumentEncryptor::class)->formatVersion('documents/absent.txt'))->toBeNull();
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

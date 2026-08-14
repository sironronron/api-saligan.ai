<?php

namespace App\Services\Documents;

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Encrypts uploaded documents at rest using per-file envelope encryption.
 *
 * Each file is encrypted with a random 32-byte data key using AES-256-CTR,
 * streamed in fixed-size blocks so arbitrarily large documents never need to
 * be held in memory. The data key is itself wrapped with the application key
 * (the same Encrypter behind the Crypt facade) and embedded in a small binary
 * header on the file, so the file is self-contained: only the application key
 * can unwrap the data key and decrypt the payload.
 *
 * The ciphertext is authenticated with HMAC-SHA256 over the whole stream
 * (encrypt-then-MAC). AES-CTR on its own provides no integrity at all: the
 * keystream is XORed with the plaintext, so flipping a bit of ciphertext flips
 * exactly that bit of the decrypted document, undetectably and without any
 * knowledge of the key. On a store of legal documents that is the difference
 * between "an attacker with disk access can read nothing" and "an attacker with
 * disk access can silently edit a contract's figures". The tag closes that.
 *
 * File layout (version 2):
 *
 *     SALIGENCDOC1            magic, 12 bytes
 *     0x02                    format version, 1 byte
 *     uint16 big-endian       length of the wrapped data key, 2 bytes
 *     wrapped data key        base64 of Encrypter::encryptString($dataKey)
 *     base IV                 8 random bytes (first half of the CTR counter)
 *     tag                     HMAC-SHA256 of version ‖ base IV ‖ ciphertext
 *     ciphertext              AES-256-CTR stream of the original file
 *
 * The encryption and authentication keys are separate, both derived from the
 * per-file data key with HKDF, so the same bytes never serve two purposes.
 *
 * The CTR counter is the base IV concatenated with a 64-bit big-endian block
 * index, so any 16-byte-aligned slice can be decrypted independently. The
 * final block of a file may be shorter than the chunk size; encryption and
 * decryption always read the same chunk boundaries.
 *
 * Version 1 files — written before the tag existed — carry no tag and are still
 * readable so a deployment can migrate without downtime; `saligan:reencrypt-documents`
 * rewrites them, after which DOCUMENT_REQUIRE_AUTHENTICATED_ENCRYPTION can be
 * turned on to refuse the unauthenticated format for good.
 */
class DocumentEncryptor
{
    /**
     * Magic bytes that identify an encrypted document file.
     */
    public const MAGIC = 'SALIGENCDOC1';

    /**
     * Current on-disk format version. Files written before authentication was
     * added carry {@see LEGACY_VERSION} and have no tag.
     */
    private const VERSION = 2;

    private const LEGACY_VERSION = 1;

    /**
     * Size of the random base IV that seeds the CTR counter.
     */
    private const BASE_IV_LENGTH = 8;

    /**
     * Size of the HMAC-SHA256 authentication tag.
     */
    private const TAG_LENGTH = 32;

    /**
     * HKDF context strings. Distinct values are what keep the encryption key
     * and the MAC key independent even though both come from one data key.
     */
    private const ENCRYPTION_INFO = 'saligan.document.encryption.v2';

    private const AUTHENTICATION_INFO = 'saligan.document.authentication.v2';

    /**
     * Bytes read from / written to the disk per cipher operation. Must be a
     * multiple of the AES block size (16 bytes) so block counters align.
     */
    private const CHUNK_SIZE = 1048576;

    public function __construct(
        private readonly Encrypter $encrypter,
    ) {
        //
    }

    /**
     * Encrypt the file at the given absolute source path into the given
     * storage path on the default disk.
     */
    public function encrypt(string $sourcePath, string $destinationPath): void
    {
        $dataKey = random_bytes(32);
        $baseIv = random_bytes(self::BASE_IV_LENGTH);
        $wrappedKey = $this->encrypter->encryptString($dataKey);

        Storage::disk($this->disk())->makeDirectory(dirname($destinationPath));

        $out = fopen(Storage::path($destinationPath), 'wb');
        $in = fopen($sourcePath, 'rb');

        if ($out === false || $in === false) {
            throw new RuntimeException('Could not open the file for encryption.');
        }

        try {
            // The tag covers the ciphertext, so it cannot be known until the
            // last block is written. Zeroes reserve its place and the real
            // value is seeked back over once the stream is complete.
            $tagOffset = $this->writeHeader($out, $wrappedKey, $baseIv);

            fwrite($out, str_repeat("\0", self::TAG_LENGTH));

            $mac = hash_init('sha256', HASH_HMAC, $this->authenticationKey($dataKey));
            hash_update($mac, chr(self::VERSION).$baseIv);

            $encryptionKey = $this->encryptionKey($dataKey);
            $offset = 0;

            while (! feof($in)) {
                $chunk = fread($in, self::CHUNK_SIZE);

                if ($chunk === false) {
                    throw new RuntimeException('Could not read the source file during encryption.');
                }

                if ($chunk === '') {
                    break;
                }

                $ciphertext = $this->cipher($chunk, $encryptionKey, $baseIv, $offset);

                hash_update($mac, $ciphertext);
                fwrite($out, $ciphertext);

                $offset += strlen($chunk);
            }

            if (fseek($out, $tagOffset) !== 0) {
                throw new RuntimeException('Could not write the authentication tag.');
            }

            fwrite($out, hash_final($mac, true));
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    /**
     * Whether the stored file carries the encrypted-document header. Files
     * written before encryption was introduced have no header and are served
     * and processed as plaintext.
     */
    public function isEncrypted(string $storagePath): bool
    {
        $fullPath = Storage::path($storagePath);

        if (! is_file($fullPath)) {
            return false;
        }

        $handle = fopen($fullPath, 'rb');

        if ($handle === false) {
            return false;
        }

        $magic = fread($handle, strlen(self::MAGIC));

        fclose($handle);

        return $magic === self::MAGIC;
    }

    /**
     * The on-disk format version of a stored file, or null when it carries no
     * encryption header at all. Lets the migration command tell an already
     * authenticated file from one still on the legacy format.
     */
    public function formatVersion(string $storagePath): ?int
    {
        if (! $this->isEncrypted($storagePath)) {
            return null;
        }

        $handle = fopen(Storage::path($storagePath), 'rb');

        if ($handle === false) {
            return null;
        }

        fread($handle, strlen(self::MAGIC));
        $version = ord((string) fread($handle, 1));

        fclose($handle);

        return $version;
    }

    /**
     * Decrypt an encrypted stored file into a temporary local file, returning
     * its absolute path. Returns null when the stored file is not encrypted.
     * The caller must delete the returned file when it is no longer needed.
     */
    public function decryptToTemp(string $storagePath): ?string
    {
        if (! $this->isEncrypted($storagePath)) {
            return null;
        }

        $directory = storage_path('tmp/documents');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $tempPath = tempnam($directory, 'saligan_');

        if ($tempPath === false) {
            throw new RuntimeException('Could not create a temporary file for decryption.');
        }

        $in = fopen(Storage::path($storagePath), 'rb');
        $out = fopen($tempPath, 'wb');

        if ($in === false || $out === false) {
            throw new RuntimeException('Could not open the file for decryption.');
        }

        try {
            $header = $this->readHeader($in);

            $this->verify($in, $header);

            $key = $this->cipherKey($header);
            $offset = 0;

            while (! feof($in)) {
                $chunk = fread($in, self::CHUNK_SIZE);

                if ($chunk === false) {
                    throw new RuntimeException('Could not read the encrypted file during decryption.');
                }

                if ($chunk === '') {
                    break;
                }

                fwrite($out, $this->cipher($chunk, $key, $header['base_iv'], $offset));

                $offset += strlen($chunk);
            }
        } finally {
            fclose($in);
            fclose($out);
        }

        return $tempPath;
    }

    /**
     * The decrypted contents of an encrypted stored file, in chunks, so it can
     * be streamed to a response without buffering the whole document in memory.
     *
     * Deliberately not a generator itself: the header is read and the tag
     * verified here, when the method is called, rather than lazily on the first
     * iteration. A caller that starts a streamed response and only then
     * discovers the file is corrupt has already sent 200 and cannot take it
     * back — the failure would arrive as a truncated download rather than an
     * error. Verifying eagerly means a tampered file fails before a byte of the
     * response is committed.
     *
     * @return \Generator<int, string>
     */
    public function decryptStream(string $storagePath): \Generator
    {
        $handle = fopen(Storage::path($storagePath), 'rb');

        if ($handle === false) {
            throw new RuntimeException('Could not open the stored file for streaming.');
        }

        try {
            $header = $this->readHeader($handle);

            $this->verify($handle, $header);
        } catch (Throwable $exception) {
            fclose($handle);

            throw $exception;
        }

        return $this->streamChunks($handle, $header);
    }

    /**
     * Yield the decrypted body of an already-verified file.
     *
     * @param  resource  $handle
     * @param  array{version: int, key: string, base_iv: string, tag: ?string, body_offset: int}  $header
     * @return \Generator<int, string>
     */
    protected function streamChunks($handle, array $header): \Generator
    {
        try {
            $key = $this->cipherKey($header);
            $offset = 0;

            while (! feof($handle)) {
                $chunk = fread($handle, self::CHUNK_SIZE);

                if ($chunk === false) {
                    throw new RuntimeException('Could not read the encrypted file during streaming.');
                }

                if ($chunk === '') {
                    break;
                }

                yield $this->cipher($chunk, $key, $header['base_iv'], $offset);

                $offset += strlen($chunk);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * The disk the encrypted files are stored on. Always the default disk so
     * Storage::fake('local') in tests keeps the files in the fake root.
     */
    protected function disk(): string
    {
        return config('filesystems.default', 'local');
    }

    /**
     * Write the fixed binary header, returning the offset the authentication
     * tag belongs at.
     *
     * @param  resource  $handle
     */
    protected function writeHeader($handle, string $wrappedKey, string $baseIv): int
    {
        fwrite($handle, self::MAGIC);
        fwrite($handle, chr(self::VERSION));
        fwrite($handle, pack('n', strlen($wrappedKey)));
        fwrite($handle, $wrappedKey);
        fwrite($handle, $baseIv);

        return strlen(self::MAGIC) + 1 + 2 + strlen($wrappedKey) + self::BASE_IV_LENGTH;
    }

    /**
     * Parse the binary header and unwrap the per-file data key, leaving the
     * handle positioned at the first byte of ciphertext.
     *
     * @param  resource  $handle
     * @return array{version: int, key: string, base_iv: string, tag: ?string, body_offset: int}
     */
    protected function readHeader($handle): array
    {
        $magic = fread($handle, strlen(self::MAGIC));

        if ($magic !== self::MAGIC) {
            throw new RuntimeException('The stored file is not an encrypted document.');
        }

        $version = ord((string) fread($handle, 1));

        if (! in_array($version, [self::VERSION, self::LEGACY_VERSION], true)) {
            throw new RuntimeException('The stored document uses an unsupported encryption format.');
        }

        if ($version === self::LEGACY_VERSION && $this->requiresAuthentication()) {
            throw new RuntimeException(
                'The stored document uses the unauthenticated v1 format, which this deployment refuses. '
                .'Run `saligan:reencrypt-documents` to upgrade it.',
            );
        }

        $keyLength = unpack('n', (string) fread($handle, 2))[1];
        $wrappedKey = (string) fread($handle, $keyLength);
        $baseIv = (string) fread($handle, self::BASE_IV_LENGTH);

        if (strlen($wrappedKey) !== $keyLength || strlen($baseIv) !== self::BASE_IV_LENGTH) {
            throw new RuntimeException('The stored document header is truncated or corrupt.');
        }

        $tag = null;

        if ($version === self::VERSION) {
            $tag = (string) fread($handle, self::TAG_LENGTH);

            if (strlen($tag) !== self::TAG_LENGTH) {
                throw new RuntimeException('The stored document header is truncated or corrupt.');
            }
        }

        try {
            $key = $this->encrypter->decryptString($wrappedKey);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'The stored document could not be decrypted with the application key.',
                0,
                $exception,
            );
        }

        if (strlen($key) !== 32) {
            throw new RuntimeException('The stored document carries an invalid data key.');
        }

        return [
            'version' => $version,
            'key' => $key,
            'base_iv' => $baseIv,
            'tag' => $tag,
            'body_offset' => (int) ftell($handle),
        ];
    }

    /**
     * Check the ciphertext against its tag, then rewind to the start of the
     * body so the caller can decrypt from the beginning.
     *
     * This costs a second pass over the file. Verifying as we decrypt would be
     * one pass, but it would also mean emitting plaintext that has not been
     * authenticated yet — which is precisely the property the tag exists to
     * provide. Correctness wins; the read is sequential and the files are
     * bounded by the upload size limit.
     *
     * @param  resource  $handle
     * @param  array{version: int, key: string, base_iv: string, tag: ?string, body_offset: int}  $header
     */
    protected function verify($handle, array $header): void
    {
        // Version 1 predates the tag. Nothing to check, and nothing that can be
        // checked — this is exactly what `requiresAuthentication()` shuts off
        // once the corpus has been migrated.
        if ($header['tag'] === null) {
            return;
        }

        $mac = hash_init('sha256', HASH_HMAC, $this->authenticationKey($header['key']));
        hash_update($mac, chr($header['version']).$header['base_iv']);

        while (! feof($handle)) {
            $chunk = fread($handle, self::CHUNK_SIZE);

            if ($chunk === false) {
                throw new RuntimeException('Could not read the encrypted file during verification.');
            }

            if ($chunk === '') {
                break;
            }

            hash_update($mac, $chunk);
        }

        if (! hash_equals(hash_final($mac, true), $header['tag'])) {
            throw new RuntimeException('The stored document failed its integrity check; it has been modified or corrupted.');
        }

        if (fseek($handle, $header['body_offset']) !== 0) {
            throw new RuntimeException('Could not rewind the encrypted file after verification.');
        }
    }

    /**
     * The AES key for a file. Version 1 used the data key directly; version 2
     * derives a dedicated one so the same bytes never both encrypt and
     * authenticate.
     *
     * @param  array{version: int, key: string, base_iv: string, tag: ?string, body_offset: int}  $header
     */
    protected function cipherKey(array $header): string
    {
        return $header['version'] === self::LEGACY_VERSION
            ? $header['key']
            : $this->encryptionKey($header['key']);
    }

    /** The per-file AES-256 key, derived from the data key. */
    protected function encryptionKey(string $dataKey): string
    {
        return hash_hkdf('sha256', $dataKey, 32, self::ENCRYPTION_INFO);
    }

    /** The per-file HMAC key, derived from the data key. */
    protected function authenticationKey(string $dataKey): string
    {
        return hash_hkdf('sha256', $dataKey, 32, self::AUTHENTICATION_INFO);
    }

    /**
     * Whether this deployment refuses the unauthenticated v1 format. Turned on
     * once `saligan:reencrypt-documents` has upgraded the existing corpus.
     */
    protected function requiresAuthentication(): bool
    {
        return (bool) config('saligan.documents.require_authenticated_encryption', false);
    }

    /**
     * Apply the AES-256-CTR keystream to a slice of the file. The counter is
     * the base IV followed by the 64-bit big-endian block index of the slice,
     * so each 16-byte-aligned slice decrypts independently of the others.
     */
    protected function cipher(string $data, string $key, string $baseIv, int $byteOffset): string
    {
        $iv = $baseIv.pack('J', intdiv($byteOffset, 16));

        $ciphertext = openssl_encrypt($data, 'aes-256-ctr', $key, OPENSSL_RAW_DATA, $iv);

        if ($ciphertext === false) {
            throw new RuntimeException('The AES-256-CTR cipher operation failed.');
        }

        return $ciphertext;
    }
}

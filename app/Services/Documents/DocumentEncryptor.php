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
 * Everything here goes through the default disk's streams rather than local
 * file paths, so the same code works on `local` and on `s3`. Object stores
 * impose two constraints that shape the implementation: their handles are not
 * seekable, and their reads are short (a `fread` for n bytes may return fewer).
 * Hence {@see readExactly} everywhere a fixed-width field is parsed, a local
 * staging file during {@see encrypt} so the tag slot can still be seeked back
 * over, and a second independent stream for {@see verify} instead of a rewind.
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
     * Whether a legacy v1 read is currently permitted despite the deployment
     * refusing that format. See {@see readingLegacy()}.
     */
    private bool $legacyReadAllowed = false;

    /**
     * Run a callback with the refusal of the unauthenticated v1 format lifted.
     *
     * The guard exists to stop v1 files being *served*, not to stop them being
     * retired: `saligan:reencrypt-documents` has to read a v1 file in order to
     * rewrite it as v2. Without this, turning the flag on before migrating
     * strands every legacy document — unreadable, and unrepairable by the one
     * command whose whole purpose is to repair it, which is exactly what the
     * refusal message tells the operator to run.
     *
     * Scoped to a callback, and restored on the way out even if it throws, so
     * no ordinary read path can ever leave the exemption switched on.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function readingLegacy(callable $callback): mixed
    {
        $previous = $this->legacyReadAllowed;
        $this->legacyReadAllowed = true;

        try {
            return $callback();
        } finally {
            $this->legacyReadAllowed = $previous;
        }
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

        // The tag covers the whole ciphertext, so it is not known until the
        // last block has been written, and the slot reserved for it has to be
        // seeked back over. An object store offers no seekable write handle, so
        // the file is assembled in a local staging file and uploaded in one
        // streamed pass once it is complete.
        $staging = $this->temporaryPath();

        $out = fopen($staging, 'w+b');
        $in = fopen($sourcePath, 'rb');

        if ($out === false || $in === false) {
            if (is_resource($out)) {
                fclose($out);
            }

            if (is_resource($in)) {
                fclose($in);
            }

            @unlink($staging);

            throw new RuntimeException('Could not open the file for encryption.');
        }

        try {
            // Zeroes reserve the tag's place; the real value is seeked back
            // over once the stream is complete.
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

            rewind($out);

            if (Storage::disk($this->disk())->writeStream($destinationPath, $out) === false) {
                throw new RuntimeException('Could not write the encrypted document to storage.');
            }
        } finally {
            fclose($in);

            // Flysystem may or may not close the handle it was handed.
            if (is_resource($out)) {
                fclose($out);
            }

            @unlink($staging);
        }
    }

    /**
     * Whether the stored file carries the encrypted-document header. Files
     * written before encryption was introduced have no header and are served
     * and processed as plaintext.
     */
    public function isEncrypted(string $storagePath): bool
    {
        $disk = Storage::disk($this->disk());

        if (! $disk->exists($storagePath)) {
            return false;
        }

        $handle = $disk->readStream($storagePath);

        if (! is_resource($handle)) {
            return false;
        }

        try {
            return $this->readExactly($handle, strlen(self::MAGIC)) === self::MAGIC;
        } finally {
            fclose($handle);
        }
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

        $handle = Storage::disk($this->disk())->readStream($storagePath);

        if (! is_resource($handle)) {
            return null;
        }

        try {
            $this->readExactly($handle, strlen(self::MAGIC));

            $version = $this->readExactly($handle, 1);

            return $version === null ? null : ord($version);
        } finally {
            fclose($handle);
        }
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

        $tempPath = $this->temporaryPath();

        $in = $this->open($storagePath);
        $out = fopen($tempPath, 'wb');

        if ($out === false) {
            fclose($in);
            @unlink($tempPath);

            throw new RuntimeException('Could not open the file for decryption.');
        }

        try {
            $header = $this->readHeader($in);

            // Verified over its own stream, leaving `$in` sitting on the first
            // byte of ciphertext — an object-store handle cannot be rewound.
            $this->verify($storagePath, $header);

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
        } catch (Throwable $exception) {
            // A half-written plaintext file is worse than none: leaving it
            // behind both leaks cleartext and invites a caller to read a
            // truncated document as if it were whole.
            fclose($out);
            @unlink($tempPath);

            throw $exception;
        } finally {
            fclose($in);

            if (is_resource($out)) {
                fclose($out);
            }
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
        $handle = $this->open($storagePath);

        try {
            $header = $this->readHeader($handle);

            $this->verify($storagePath, $header);
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
     * Open a read stream on a stored file.
     *
     * @return resource
     */
    protected function open(string $storagePath)
    {
        $handle = Storage::disk($this->disk())->readStream($storagePath);

        if (! is_resource($handle)) {
            throw new RuntimeException('Could not open the stored file for reading.');
        }

        return $handle;
    }

    /**
     * Read exactly $length bytes, or null if the stream ends first.
     *
     * A single fread() is not enough: on a network-backed stream it returns
     * whatever bytes have arrived, which for a fixed-width header field means
     * a short read parses as a corrupt file rather than a slow one.
     *
     * @param  resource  $handle
     */
    protected function readExactly($handle, int $length): ?string
    {
        if ($length <= 0) {
            return '';
        }

        $buffer = '';

        while (strlen($buffer) < $length) {
            $piece = fread($handle, $length - strlen($buffer));

            if ($piece === false || $piece === '') {
                break;
            }

            $buffer .= $piece;
        }

        return strlen($buffer) === $length ? $buffer : null;
    }

    /**
     * An empty local temporary file for staging plaintext or ciphertext. The
     * caller owns it and must unlink it.
     */
    protected function temporaryPath(): string
    {
        $directory = storage_path('tmp/documents');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $tempPath = tempnam($directory, 'saligan_');

        if ($tempPath === false) {
            throw new RuntimeException('Could not create a temporary file.');
        }

        return $tempPath;
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
        $magic = $this->readExactly($handle, strlen(self::MAGIC));

        if ($magic !== self::MAGIC) {
            throw new RuntimeException('The stored file is not an encrypted document.');
        }

        $version = ord((string) $this->readExactly($handle, 1));

        if (! in_array($version, [self::VERSION, self::LEGACY_VERSION], true)) {
            throw new RuntimeException('The stored document uses an unsupported encryption format.');
        }

        if ($version === self::LEGACY_VERSION && $this->requiresAuthentication()) {
            throw new RuntimeException(
                'The stored document uses the unauthenticated v1 format, which this deployment refuses. '
                .'Run `saligan:reencrypt-documents` to upgrade it.',
            );
        }

        $packedLength = $this->readExactly($handle, 2);

        if ($packedLength === null) {
            throw new RuntimeException('The stored document header is truncated or corrupt.');
        }

        $keyLength = unpack('n', $packedLength)[1];
        $wrappedKey = $this->readExactly($handle, $keyLength);
        $baseIv = $this->readExactly($handle, self::BASE_IV_LENGTH);

        if ($wrappedKey === null || $baseIv === null) {
            throw new RuntimeException('The stored document header is truncated or corrupt.');
        }

        $tag = null;

        if ($version === self::VERSION) {
            $tag = $this->readExactly($handle, self::TAG_LENGTH);

            if ($tag === null) {
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
            // Computed rather than read off ftell(), which is not meaningful
            // on every stream wrapper the disks can hand back.
            'body_offset' => strlen(self::MAGIC) + 1 + 2 + $keyLength + self::BASE_IV_LENGTH
                + ($tag === null ? 0 : self::TAG_LENGTH),
        ];
    }

    /**
     * Check a stored file's ciphertext against its tag.
     *
     * This costs a second pass over the file. Verifying as we decrypt would be
     * one pass, but it would also mean emitting plaintext that has not been
     * authenticated yet — which is precisely the property the tag exists to
     * provide. Correctness wins; the read is sequential and the files are
     * bounded by the upload size limit.
     *
     * The pass runs over its own stream rather than the caller's. Rewinding
     * after verification is not an option on an object store, whose handles are
     * forward-only, so the caller keeps a handle parked at the first byte of
     * ciphertext while this one is opened, consumed, and thrown away. On S3
     * that is a second GET; on a local disk it is a second open.
     *
     * @param  array{version: int, key: string, base_iv: string, tag: ?string, body_offset: int}  $header
     */
    protected function verify(string $storagePath, array $header): void
    {
        // Version 1 predates the tag. Nothing to check, and nothing that can be
        // checked — this is exactly what `requiresAuthentication()` shuts off
        // once the corpus has been migrated.
        if ($header['tag'] === null) {
            return;
        }

        $handle = $this->open($storagePath);

        try {
            // Skipped by reading, not seeking: forward-only streams cannot jump.
            if ($this->readExactly($handle, $header['body_offset']) === null) {
                throw new RuntimeException('The stored document header is truncated or corrupt.');
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
        } finally {
            fclose($handle);
        }

        if (! hash_equals(hash_final($mac, true), $header['tag'])) {
            throw new RuntimeException('The stored document failed its integrity check; it has been modified or corrupted.');
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
        if ($this->legacyReadAllowed) {
            return false;
        }

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

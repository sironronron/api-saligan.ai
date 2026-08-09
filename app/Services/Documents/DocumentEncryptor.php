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
 * File layout:
 *
 *     SALIGENCDOC1            magic, 12 bytes
 *     0x01                    format version, 1 byte
 *     uint16 big-endian       length of the wrapped data key, 2 bytes
 *     wrapped data key        base64 of Encrypter::encryptString($dataKey)
 *     base IV                 8 random bytes (first half of the CTR counter)
 *     ciphertext              AES-256-CTR stream of the original file
 *
 * The CTR counter is the base IV concatenated with a 64-bit big-endian block
 * index, so any 16-byte-aligned slice can be decrypted independently. The
 * final block of a file may be shorter than the chunk size; encryption and
 * decryption always read the same chunk boundaries.
 */
class DocumentEncryptor
{
    /**
     * Magic bytes that identify an encrypted document file.
     */
    public const MAGIC = 'SALIGENCDOC1';

    /**
     * Current on-disk format version.
     */
    private const VERSION = 1;

    /**
     * Size of the random base IV that seeds the CTR counter.
     */
    private const BASE_IV_LENGTH = 8;

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
            $this->writeHeader($out, $wrappedKey, $baseIv);

            $offset = 0;

            while (! feof($in)) {
                $chunk = fread($in, self::CHUNK_SIZE);

                if ($chunk === false) {
                    throw new RuntimeException('Could not read the source file during encryption.');
                }

                if ($chunk === '') {
                    break;
                }

                fwrite($out, $this->cipher($chunk, $dataKey, $baseIv, $offset));

                $offset += strlen($chunk);
            }
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
            [$key, $baseIv] = $this->readHeader($in);

            $offset = 0;

            while (! feof($in)) {
                $chunk = fread($in, self::CHUNK_SIZE);

                if ($chunk === false) {
                    throw new RuntimeException('Could not read the encrypted file during decryption.');
                }

                if ($chunk === '') {
                    break;
                }

                fwrite($out, $this->cipher($chunk, $key, $baseIv, $offset));

                $offset += strlen($chunk);
            }
        } finally {
            fclose($in);
            fclose($out);
        }

        return $tempPath;
    }

    /**
     * A generator that yields the decrypted contents of an encrypted stored
     * file in chunks, so it can be streamed to a response without buffering
     * the whole document in memory.
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
            [$key, $baseIv] = $this->readHeader($handle);

            $offset = 0;

            while (! feof($handle)) {
                $chunk = fread($handle, self::CHUNK_SIZE);

                if ($chunk === false) {
                    throw new RuntimeException('Could not read the encrypted file during streaming.');
                }

                if ($chunk === '') {
                    break;
                }

                yield $this->cipher($chunk, $key, $baseIv, $offset);

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
     * Write the fixed binary header followed by the encrypted payload.
     *
     * @param  resource  $handle
     */
    protected function writeHeader($handle, string $wrappedKey, string $baseIv): void
    {
        fwrite($handle, self::MAGIC);
        fwrite($handle, chr(self::VERSION));
        fwrite($handle, pack('n', strlen($wrappedKey)));
        fwrite($handle, $wrappedKey);
        fwrite($handle, $baseIv);
    }

    /**
     * Parse the binary header and unwrap the per-file data key.
     *
     * @param  resource  $handle
     * @return array{0: string, 1: string}
     */
    protected function readHeader($handle): array
    {
        $magic = fread($handle, strlen(self::MAGIC));

        if ($magic !== self::MAGIC) {
            throw new RuntimeException('The stored file is not an encrypted document.');
        }

        $version = ord((string) fread($handle, 1));

        if ($version !== self::VERSION) {
            throw new RuntimeException('The stored document uses an unsupported encryption format.');
        }

        $keyLength = unpack('n', (string) fread($handle, 2))[1];
        $wrappedKey = (string) fread($handle, $keyLength);
        $baseIv = (string) fread($handle, self::BASE_IV_LENGTH);

        if (strlen($wrappedKey) !== $keyLength || strlen($baseIv) !== self::BASE_IV_LENGTH) {
            throw new RuntimeException('The stored document header is truncated or corrupt.');
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

        return [$key, $baseIv];
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

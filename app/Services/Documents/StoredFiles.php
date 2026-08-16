<?php

namespace App\Services\Documents;

use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Bridges stored files to the parts of the pipeline that need a real local
 * path — text extraction, OCR, and .docx filling.
 *
 * On a local disk the stored file already is a local path and is handed back
 * untouched. On an object store it is streamed down to a temporary file first.
 * Encrypted documents are decrypted on the way, so callers get plaintext at a
 * path regardless of the disk or whether the file was encrypted at rest.
 */
class StoredFiles
{
    /**
     * Bytes moved per read when streaming a remote file down to disk.
     */
    private const CHUNK_SIZE = 1048576;

    public function __construct(
        private readonly DocumentEncryptor $encryptor,
    ) {
        //
    }

    /**
     * The plaintext contents of a stored file at a local path.
     *
     * The returned copy must be released with {@see LocalCopy::discard()} once
     * the caller is finished, which is a no-op when the path is the stored file
     * itself rather than a scratch download.
     */
    public function localCopy(string $storagePath, ?string $disk = null): LocalCopy
    {
        // Decryption already lands in a temporary local file, so an encrypted
        // document needs no separate download whichever disk it lives on.
        $decrypted = $this->encryptor->decryptToTemp($storagePath);

        if ($decrypted !== null) {
            return LocalCopy::temporary($decrypted);
        }

        return $this->plaintextCopy($storagePath, $disk);
    }

    /**
     * A local path for a stored file that is known not to be encrypted.
     */
    public function plaintextCopy(string $storagePath, ?string $disk = null): LocalCopy
    {
        $disk ??= $this->defaultDisk();

        if ($this->isLocal($disk)) {
            return LocalCopy::permanent(Storage::disk($disk)->path($storagePath));
        }

        return LocalCopy::temporary($this->download($storagePath, $disk));
    }

    /**
     * Stream a stored file down to a temporary local file, returning its path.
     */
    protected function download(string $storagePath, string $disk): string
    {
        $source = Storage::disk($disk)->readStream($storagePath);

        if (! is_resource($source)) {
            throw new RuntimeException('The stored file could not be read from storage.');
        }

        $tempPath = $this->temporaryPath();
        $target = fopen($tempPath, 'wb');

        if ($target === false) {
            fclose($source);
            @unlink($tempPath);

            throw new RuntimeException('Could not create a temporary file for the stored document.');
        }

        try {
            while (! feof($source)) {
                $chunk = fread($source, self::CHUNK_SIZE);

                if ($chunk === false) {
                    throw new RuntimeException('The stored file could not be read from storage.');
                }

                if ($chunk === '') {
                    break;
                }

                if (fwrite($target, $chunk) === false) {
                    throw new RuntimeException('Could not write the stored document to a temporary file.');
                }
            }
        } catch (Throwable $exception) {
            // A truncated download would be parsed as a corrupt document rather
            // than as the transfer failure it is.
            fclose($target);
            @unlink($tempPath);

            throw $exception;
        } finally {
            fclose($source);

            if (is_resource($target)) {
                fclose($target);
            }
        }

        return $tempPath;
    }

    /**
     * Whether a disk is backed by the local filesystem, and so already offers
     * usable paths. Read from config rather than probed off the adapter:
     * FilesystemAdapter::path() answers for an S3 disk too, but with an object
     * key that no file function can open.
     */
    protected function isLocal(string $disk): bool
    {
        return config("filesystems.disks.{$disk}.driver") === 'local';
    }

    protected function defaultDisk(): string
    {
        return config('filesystems.default', 'local');
    }

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
}

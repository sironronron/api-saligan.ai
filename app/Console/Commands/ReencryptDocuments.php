<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\Documents\DocumentEncryptor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Rewrite documents stored in the legacy v1 encryption format as authenticated
 * v2 files.
 *
 * v1 encrypts with AES-256-CTR and nothing else. CTR is a stream cipher, so
 * ciphertext bits map one-to-one onto plaintext bits: anyone who can write to
 * the storage disk can alter a stored contract — change a figure, a date, a
 * name — and the file still decrypts cleanly, with no way for the application
 * to notice. v2 adds an HMAC-SHA256 tag over the ciphertext, which turns that
 * silent edit into a hard failure.
 *
 * The rewrite is per-file and restartable: each document is decrypted to a
 * temporary file, re-encrypted alongside the original, and only then moved into
 * place, so an interruption leaves every document readable in one format or the
 * other and never half-written.
 *
 * Once this reports no remaining v1 files, set
 * DOCUMENT_REQUIRE_AUTHENTICATED_ENCRYPTION=true so the old format is refused.
 */
class ReencryptDocuments extends Command
{
    protected $signature = 'saligan:reencrypt-documents
        {--dry-run : Report what would be rewritten without changing anything}
        {--chunk=100 : Documents loaded per iteration}';

    protected $description = 'Rewrite legacy unauthenticated document files in the authenticated format';

    public function handle(DocumentEncryptor $encryptor): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->comment('Dry run: no files will be modified.');
        }

        $upgraded = 0;
        $skipped = 0;
        $failed = 0;

        Document::query()
            ->whereNotNull('storage_path')
            ->orderBy('id')
            ->chunkById((int) $this->option('chunk'), function ($documents) use ($encryptor, $dryRun, &$upgraded, &$skipped, &$failed): void {
                foreach ($documents as $document) {
                    // Plaintext files (null) predate encryption entirely and
                    // are left alone; v2 files are already authenticated.
                    // Only v1 is the format this command exists to retire.
                    if ($encryptor->formatVersion($document->storage_path) !== 1) {
                        $skipped++;

                        continue;
                    }

                    if ($dryRun) {
                        $this->line("Would rewrite <info>{$document->storage_path}</info>");
                        $upgraded++;

                        continue;
                    }

                    try {
                        $this->rewrite($encryptor, $document->storage_path);
                        $upgraded++;
                    } catch (Throwable $exception) {
                        $failed++;

                        $this->error("Failed on {$document->storage_path}: {$exception->getMessage()}");
                    }
                }
            });

        $this->newLine();
        $this->line("Rewritten: <info>{$upgraded}</info>  Skipped: <info>{$skipped}</info>  Failed: <info>{$failed}</info>");

        if ($failed > 0) {
            $this->warn('Some documents could not be rewritten. Re-run to retry only those; upgraded files are skipped.');

            return self::FAILURE;
        }

        if ($upgraded > 0 && ! $dryRun) {
            $this->info('Set DOCUMENT_REQUIRE_AUTHENTICATED_ENCRYPTION=true to refuse the legacy format from now on.');
        }

        return self::SUCCESS;
    }

    /**
     * Rewrite one stored file in place, via a temporary copy so a failure
     * partway through never leaves the document truncated.
     */
    protected function rewrite(DocumentEncryptor $encryptor, string $storagePath): void
    {
        $plaintextPath = $encryptor->decryptToTemp($storagePath);

        if ($plaintextPath === null) {
            throw new \RuntimeException('The file is not encrypted.');
        }

        $stagingPath = $storagePath.'.reencrypting';

        try {
            $encryptor->encrypt($plaintextPath, $stagingPath);

            // Proved readable before the original is replaced: re-encrypting is
            // pointless if the result cannot be decrypted back.
            $verifyPath = $encryptor->decryptToTemp($stagingPath);

            if ($verifyPath === null) {
                throw new \RuntimeException('The rewritten file could not be verified.');
            }

            $matches = hash_file('sha256', $verifyPath) === hash_file('sha256', $plaintextPath);

            @unlink($verifyPath);

            if (! $matches) {
                throw new \RuntimeException('The rewritten file did not decrypt back to the original contents.');
            }

            Storage::delete($storagePath);
            Storage::move($stagingPath, $storagePath);
        } catch (Throwable $exception) {
            Storage::delete($stagingPath);

            throw $exception;
        } finally {
            @unlink($plaintextPath);
        }
    }
}

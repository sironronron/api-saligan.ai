<?php

namespace App\Services\Documents;

/**
 * A stored file made available at a real local filesystem path.
 *
 * The text extractors, the OCR pipeline, and the .docx filler all shell out to
 * or memory-map an actual file, so they need a path rather than a stream. On a
 * local disk that is the stored file itself; on an object store it has to be
 * downloaded first. Callers should not care which, but they do have to release
 * it — hence {@see discard}, which deletes the file only when this copy owns it
 * and never touches the original on a local disk.
 */
final class LocalCopy
{
    private function __construct(
        public readonly string $path,
        private readonly bool $temporary,
    ) {
        //
    }

    /**
     * A path that already exists on the local filesystem and outlives the
     * caller — the stored file itself, on a local disk.
     */
    public static function permanent(string $path): self
    {
        return new self($path, false);
    }

    /**
     * A scratch file the caller must release when it is done with it.
     */
    public static function temporary(string $path): self
    {
        return new self($path, true);
    }

    /**
     * Whether this copy is a scratch file rather than the stored file itself.
     */
    public function isTemporary(): bool
    {
        return $this->temporary;
    }

    /**
     * Release the copy. A no-op when the path is the stored file, so this is
     * always safe to call in a `finally`.
     */
    public function discard(): void
    {
        if ($this->temporary) {
            @unlink($this->path);
        }
    }
}

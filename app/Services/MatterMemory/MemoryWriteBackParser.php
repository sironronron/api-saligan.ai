<?php

namespace App\Services\MatterMemory;

use App\Models\LegalCase;
use App\Models\MatterMemory;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class MemoryWriteBackParser
{
    /**
     * Pattern to match memory write-back blocks in AI output.
     * Format: [[MEMORY_WRITE_START]] matter=ID type=TYPE content: CONTENT [[MEMORY_WRITE_END]]
     */
    private const WRITE_BACK_PATTERN = '/\[\[MEMORY_WRITE_START\]\]\s*matter=(\S+)\s+type=(\S+)\s+content:\s*(.*?)\s*\[\[MEMORY_WRITE_END\]\]/s';

    /**
     * Extract memory write-back blocks from AI output and store them.
     * Returns the cleaned text with write-back blocks removed.
     */
    public function parseAndStore(
        string $text,
        LegalCase $case,
        User $user,
        MatterMemoryService $memoryService,
    ): string {
        if (! preg_match_all(self::WRITE_BACK_PATTERN, $text, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL)) {
            return $text;
        }

        $cleanedText = $text;

        foreach ($matches as $match) {
            $matterId = $match[1];
            $type = $match[2];
            $content = trim($match[3]);
            $fullMatch = $match[0];

            // Validate matter ID matches the current case
            if ($matterId !== $case->id) {
                Log::warning('Memory write-back matter ID mismatch', [
                    'expected' => $case->id,
                    'received' => $matterId,
                ]);

                continue;
            }

            // Validate memory type
            if (! in_array($type, MatterMemory::TYPES, true)) {
                Log::warning('Invalid memory write-back type', [
                    'type' => $type,
                ]);

                continue;
            }

            // Check retention status
            if (! $memoryService->canWrite($case)) {
                Log::info('Memory write blocked due to retention status', [
                    'case_id' => $case->id,
                    'retention_status' => $case->retention_status,
                ]);

                continue;
            }

            // Check for duplicates
            if ($memoryService->existsSimilar($case, $type, $content)) {
                Log::info('Duplicate memory write skipped', [
                    'case_id' => $case->id,
                    'type' => $type,
                ]);

                continue;
            }

            // Store the memory
            $memoryService->store($case, $user, $type, $content);

            Log::info('Memory write-back stored', [
                'case_id' => $case->id,
                'type' => $type,
                'content_length' => strlen($content),
            ]);

            // Remove only successfully stored blocks from the text
            $cleanedText = str_replace($fullMatch, '', $cleanedText);
        }

        return $cleanedText;
    }

    /**
     * Check if the text contains any memory write-back blocks.
     */
    public function hasWriteBackBlocks(string $text): bool
    {
        return (bool) preg_match(self::WRITE_BACK_PATTERN, $text);
    }
}

<?php

namespace App\Support;

/**
 * Recovers the caveats out of a reply that wrote them as prose instead of
 * filing them through flag_advisories.
 *
 * Models skip tool calls — create_todo has carried a text fallback for exactly
 * this reason since before advisories existed, and a research turn is the case
 * where a skip is most likely, since the model is already writing the section
 * the persona describes. Without this, the answer's most important part would
 * silently never reach the panel.
 */
final class AdvisoryParser
{
    /**
     * Headings that open a caveats section. "Next steps" is deliberately absent:
     * those are tasks and belong to create_todo.
     */
    private const HEADING_PATTERN = '/^\s*(?:#{1,6}\s*)?(?:\d+[.)]\s*)?\**\s*(caveats?(?:\s*(?:,|and)\s*next\s+steps?)?|limitations?|important\s+considerations?|things?\s+to\s+watch\s+(?:out\s+)?for|risks?\s+and\s+caveats?|what\s+to\s+watch\s+out\s+for)[\s:*]*$/i';

    /**
     * Headings that close it — anything that starts a different section.
     */
    private const CLOSING_PATTERN = '/^\s*(?:#{1,6}\s*)?(?:\d+[.)]\s*)?\**\s*(sources?|next\s+steps?|references?|disclaimer|conclusion|summary|direct\s+answer|legal\s+basis|application)\b/i';

    /**
     * The caveats written into a reply, as items AdvisoryRecorder can store.
     *
     * @return array<int, array<string, string>>
     */
    public static function fromReply(string $text): array
    {
        $lines = preg_split('/\R/', $text) ?: [];

        $items = [];
        $inSection = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                continue;
            }

            if (preg_match(self::HEADING_PATTERN, $trimmed) === 1) {
                $inSection = true;

                continue;
            }

            if (! $inSection) {
                continue;
            }

            // A drafted document's own body must never be mined for caveats:
            // the section, if there is one, sits outside the markers.
            if (preg_match(self::CLOSING_PATTERN, $trimmed) === 1
                || str_contains($trimmed, '[[DOCUMENT_START]]')
                || str_contains($trimmed, '[[TODO_START]]')) {
                break;
            }

            $title = self::titleFrom($trimmed);

            if ($title !== null) {
                $items[] = ['kind' => 'caveat', 'title' => $title, 'severity' => 'medium'];
            }
        }

        return $items;
    }

    /**
     * Whether a reply wrote a caveats section at all — the condition for the
     * fallback running.
     */
    public static function hasSection(string $text): bool
    {
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (preg_match(self::HEADING_PATTERN, trim($line)) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * One caveat from one line, or null when the line is not a caveat.
     *
     * Only bulleted and numbered lines qualify. Free prose inside the section
     * is left alone: a wrapped sentence would otherwise arrive as two truncated
     * half-caveats, and half a caveat shown as a real one is worse than none.
     */
    private static function titleFrom(string $line): ?string
    {
        if (preg_match('/^(?:[-*•]|\d+[.)])\s+(.{12,})$/u', $line, $matches) !== 1) {
            return null;
        }

        // Strip markdown emphasis and a leading "Label:" lead-in, keeping the
        // substance that follows it.
        $title = trim((string) preg_replace('/[*_`]/', '', $matches[1]));
        $title = trim((string) preg_replace('/^[A-Z][A-Za-z\s]{0,24}:\s*/', '', $title));

        if (mb_strlen($title) < 12 || ! str_contains($title, ' ')) {
            return null;
        }

        return mb_strimwidth($title, 0, 255, '…');
    }
}

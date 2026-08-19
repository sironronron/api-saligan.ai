<?php

namespace App\Support;

/**
 * Catches the reply's claims about its own actions and checks them against
 * what the turn actually did.
 *
 * Models narrate tool use whether or not tool use happened. "I searched the
 * Supreme Court E-Library and confirmed...", "I've added these to your task
 * list", "I've emailed the draft to your client" — each is an ordinary
 * sentence for a model to write and each is, on a turn where the tool never
 * ran, a lie the user has no way to detect. In a legal product the web-search
 * version is the dangerous one: it dresses a memory-recalled citation in the
 * authority of a source that was never fetched.
 *
 * Nothing here rewrites the model's prose. Deleting sentences mid-answer
 * produces text that reads as if it were corrupted, and the model's actual
 * legal content is usually still worth reading. Instead the unsupported claim
 * is reported to the client as a notice shown with the message, and the one
 * artefact that is machine-checkable and actively misleading — a "[Web N]"
 * marker with no source behind it — is stripped.
 */
final class ToolClaimGuard
{
    /**
     * The subject of a claim: a first-person "I", optionally reaching across a
     * conjoined clause to the verb ("I have emailed the notice **and filed**
     * the petition"). Anchoring on the pronoun is what keeps advice to the
     * reader — "you can file this with the Register of Deeds" — out of the
     * matches; reaching past the "and" is what stops a model from hiding a
     * second claim behind a first one.
     */
    private const SUBJECT = '\bI(?:\'ve|\s+have)?\s+(?:[^.!?\n]{0,80}?\band\s+)?';

    /**
     * Phrasings that assert a web search was performed. Deliberately anchored
     * on a first-person verb: "you can search the web for..." is advice, not a
     * claim, and must not trip this.
     */
    private const WEB_SEARCH_CLAIMS = [
        '/'.self::SUBJECT.'(?:just\s+)?(?:searched|checked|looked\s+up|verified|confirmed|researched|browsed|reviewed)\b[^.!?\n]{0,60}\b(?:the\s+)?(?:web|internet|online|e-?library|lawphil|official\s+gazette|supreme\s+court\s+website)\b/i',
        '/\b(?:a|my|the)\s+(?:web\s+)?search\s+(?:I\s+ran|of\s+the\s+web|results?)\b[^.!?\n]{0,40}\b(?:show|shows|showed|confirm|confirms|confirmed|reveal|reveals|revealed)\b/i',
        '/\b(?:according\s+to|based\s+on)\s+(?:my|the)\s+(?:web\s+)?search\b/i',
    ];

    /**
     * Phrasings that assert tasks were written to the user's task list.
     */
    private const TODO_CLAIMS = [
        '/'.self::SUBJECT.'(?:added|created|saved|logged|set\s+up)\b[^.!?\n]{0,60}\b(?:task|tasks|to-?do|to-?dos|checklist|next\s+steps|reminders?)\b/i',
        '/\b(?:these|the\s+following)\s+(?:tasks|to-?dos|next\s+steps)\s+(?:have\s+been|were)\s+(?:added|created|saved)\b/i',
    ];

    /**
     * Phrasings that assert a letter was placed in the in-app editor.
     */
    private const LETTER_CLAIMS = [
        '/'.self::SUBJECT.'(?:drafted|prepared|opened|placed|loaded)\b[^.!?\n]{0,60}\b(?:in|into|to)\s+the\s+(?:letter\s+)?editor\b/i',
        '/\bthe\s+(?:letter|draft|document)\s+(?:is\s+now|has\s+been)\s+(?:open|opened|loaded)\s+in\s+the\s+editor\b/i',
    ];

    /**
     * Phrasings that assert capabilities the product simply does not have on a
     * chat turn. These are never satisfiable, so they need no run log to
     * contradict them.
     */
    private const IMPOSSIBLE_CLAIMS = [
        'email' => '/'.self::SUBJECT.'(?:sent|emailed|e-?mailed|forwarded|mailed)\b[^.!?\n]{0,50}\b(?:to\s+(?:your|the)\b|via\s+e-?mail|by\s+e-?mail)/i',
        'filing' => '/'.self::SUBJECT.'(?:filed|submitted|lodged|transmitted)\b[^.!?\n]{0,50}\b(?:with|to|before)\s+(?:the\s+)?(?:court|register\s+of\s+deeds|registry|bir|sec|dar|lra|agency|office)\b/i',
        'calendar' => '/'.self::SUBJECT.'(?:scheduled|booked|set\s+up|added)\b[^.!?\n]{0,50}\b(?:calendar|appointment|hearing\s+date)\b/i',
        'call' => '/'.self::SUBJECT.'(?:called|contacted|reached\s+out\s+to|spoken\s+(?:with|to))\b[^.!?\n]{0,50}\b(?:the\s+)?(?:court|clerk|office|agency|register)\b/i',
    ];

    /**
     * User-facing wording for each notice kind. Written to be read under a
     * legal answer: it says what did not happen and what the reader should do
     * about it, without accusing the model of anything the reader has to
     * interpret.
     */
    private const MESSAGES = [
        'web_search' => 'This answer says the web was checked, but no web search ran for it. Treat any authority named here as unverified and confirm it against the primary source.',
        'web_markers' => 'Some source references in this answer point to results that were never returned. They have been removed — the surrounding statements are unverified.',
        'todo' => 'This answer says tasks were added, but nothing was written to your task list. Add them yourself if you want them tracked.',
        'letter' => 'This answer says a draft was opened in the editor, but no draft was produced. Ask again to have the letter drafted.',
        'email' => 'Batayan cannot send email. Nothing was sent — the draft is here for you to send yourself.',
        'filing' => 'Batayan cannot file or submit anything with a court or agency. Nothing was filed.',
        'calendar' => 'Batayan did not schedule anything. Any date mentioned here is a suggestion, not a booking.',
        'call' => 'Batayan cannot contact a court, agency, or third party. No one was contacted.',
    ];

    /**
     * Inspect a finished reply against the turn's tool record.
     *
     * @param  int  $webCitations  Sources the turn actually produced.
     * @param  bool  $todosRecovered  The controller's text fallback wrote the
     *                                tasks the model only described, which
     *                                makes the "I added tasks" claim true.
     * @param  bool  $letterProduced  A letter reached the editor by any route.
     * @return array<int, array{kind: string, message: string}>
     */
    public static function inspect(
        string $text,
        ToolRunLog $runs,
        int $webCitations = 0,
        bool $todosRecovered = false,
        bool $letterProduced = false,
    ): array {
        if (trim($text) === '') {
            return [];
        }

        $notices = [];

        // A completed search that returned nothing still supports "I checked
        // the web" — the check happened and came back empty, which is exactly
        // what the tool tells the model to say.
        if (! $runs->completed('web_search') && $webCitations === 0 && self::matchesAny($text, self::WEB_SEARCH_CLAIMS)) {
            $notices[] = 'web_search';
        }

        if (self::hasUnsupportedWebMarkers($text, $webCitations)) {
            $notices[] = 'web_markers';
        }

        if (! $runs->called('create_todo') && ! $todosRecovered && self::matchesAny($text, self::TODO_CLAIMS)) {
            $notices[] = 'todo';
        }

        if (! $letterProduced && ! $runs->called('draft_letter') && self::matchesAny($text, self::LETTER_CLAIMS)) {
            $notices[] = 'letter';
        }

        foreach (self::IMPOSSIBLE_CLAIMS as $kind => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                $notices[] = $kind;
            }
        }

        return array_values(array_map(
            fn (string $kind): array => ['kind' => $kind, 'message' => self::MESSAGES[$kind]],
            array_unique($notices),
        ));
    }

    /**
     * Whether the reply carries a "[Web N]" marker with no source behind it —
     * either because no search ran at all, or because the model numbered past
     * the results it was given.
     */
    public static function hasUnsupportedWebMarkers(string $text, int $webCitations): bool
    {
        if (preg_match_all('/\[Web\s+(\d+)\]/i', $text, $matches) === 0) {
            return false;
        }

        foreach ($matches[1] as $index) {
            if ((int) $index < 1 || (int) $index > $webCitations) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $patterns
     */
    private static function matchesAny(string $text, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }
}

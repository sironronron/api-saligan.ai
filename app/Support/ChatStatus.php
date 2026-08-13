<?php

namespace App\Support;

use Illuminate\Support\Str;

final class ChatStatus
{
    /**
     * Common leading question words and filler stripped when deriving a short
     * topic phrase from a user message, so the status label reflects what the
     * user actually asked about instead of echoing the whole question.
     *
     * @var array<int, string>
     */
    private const LEADING_WORDS = [
        'what', 'whats', 'what is', 'what are', 'what does', 'what do',
        'how', 'how do', 'how does', 'how can', 'how to', 'how is',
        'why', 'why does', 'why do', 'when', 'when does', 'when can',
        'where', 'where can', 'who', 'which', 'can', 'can i', 'could',
        'could you', 'would', 'would you', 'should', 'should i', 'do',
        'does', 'did', 'is', 'are', 'was', 'were', 'please', 'explain',
        'tell me', 'about', 'regarding', 'i want to know', 'i need',
        'help me', 'help me understand', 'give me', 'what happens',
    ];

    /**
     * Words with no topical content of their own. Only ever trimmed from the
     * front of a candidate phrase — inside one they carry meaning ("scope of
     * the program" needs its "of the").
     *
     * @var array<int, string>
     */
    private const LEADING_FILLER = [
        'the', 'a', 'an', 'of', 'for', 'on', 'in', 'to', 'about', 'regarding',
        'me', 'us', 'my', 'our', 'your', 'it', 'that', 'this', 'there',
        'i', 'we', 'you',
    ];

    /**
     * Build the activity label shown for a streaming step given the user's
     * message, so each step reads as if it were written for that question
     * instead of a generic status. Falls back to a neutral label when no
     * topic can be derived.
     */
    public static function label(string $status, string $question): string
    {
        // Steps no longer carry the topic. It used to be appended to every
        // one of them, which meant the same phrase was repeated on the header
        // and on each timeline row — and any flaw in the extraction was
        // repeated with it. The topic now travels once, on its own field, and
        // the steps say only what is being done.
        return match ($status) {
            'checking_sources' => 'Checking legal sources',
            'searching_web' => 'Searching the web',
            'gathering_facts' => ($document = self::document($question)) !== null
                ? "Gathering the facts for your {$document}"
                : 'Gathering the facts needed',
            'drafting_document' => ($document = self::document($question)) !== null
                ? "Drafting your {$document}"
                : 'Drafting your document',
            'preparing_next_steps' => 'Preparing your next steps',
            'collecting_facts' => 'Collecting the facts I need',
            'filling_template' => 'Filling in your template',
            'composing' => 'Writing your answer',
            default => Str::headline($status),
        };
    }

    /**
     * A short, lowercased phrase naming the document being drafted (e.g.
     * "demand letter", "deed of absolute sale") when the message names one,
     * so the drafting statuses read as if written for that document. Falls
     * back to the generic document category, then to null. Intake form
     * submissions carry field values rather than a request, so they never
     * produce a document name.
     */
    private static function document(string $question): ?string
    {
        if (str_starts_with($question, '[Intake Form Submission]')) {
            return null;
        }

        $template = LegalTemplateLibrary::resolveForMessage($question);

        if ($template !== null && filled($template['document_type'] ?? null)) {
            $name = Str::of($template['document_type'])
                ->replace('_', ' ')
                ->remove(' general')
                ->lower()
                ->trim()
                ->toString();

            if ($name !== '' && $name !== 'custom') {
                return $name;
            }
        }

        $category = DraftingIntent::documentTypeFor($question);

        return $category !== null ? mb_strtolower($category) : null;
    }

    /**
     * Derive a short, human-readable topic from the user's message: the key
     * legal reference when the message names one (e.g. "RA 6657"), otherwise
     * the first few meaningful words after question stems are stripped.
     */
    public static function topic(string $question): ?string
    {
        [, $text] = DraftingIntent::extractTemplateDirective($question);

        $text = trim($text);

        if ($text === '' || str_starts_with($text, '[Intake Form Submission]')) {
            return null;
        }

        $citation = self::citation($text);

        if ($citation !== null) {
            return $citation;
        }

        $text = self::stripLeadingStems($text);

        $words = self::words($text);

        if ($words === []) {
            return null;
        }

        // A run of capitalised words is almost always the thing being asked
        // about — "Comprehensive Agrarian Reform Program" out of "What is the
        // scope of the Comprehensive Agrarian Reform Program?" — and it beats
        // any positional guess, so it is preferred when the question has one.
        $proper = self::properNounRun($words);

        if ($proper !== null) {
            return self::clip($proper, preserveCase: true);
        }

        // Eight rather than five: the 60-character clip already bounds the
        // length, and a tighter word budget was cutting phrases mid-thought
        // ("requirements for a deed of absolute" losing its "sale").
        $words = array_slice($words, 0, 8);

        // Never end on a dangling connector.
        while ($words !== [] && in_array(mb_strtolower(end($words)), ['of', 'and', 'for', 'the', 'a', 'an', 'to', 'in', 'on'], true)) {
            array_pop($words);
        }

        if ($words === []) {
            return null;
        }

        return self::clip(implode(' ', $words), preserveCase: false);
    }

    /**
     * Strip question stems and leading filler off the front of the message.
     *
     * Longest stem first and repeatedly, which is the part the previous
     * shortest-first single-pass version got wrong: "what" matched before
     * "what is" ever could, so "What is the scope of X" was left as "is the
     * scope of X" and the first few words of that became the topic.
     */
    private static function stripLeadingStems(string $text): string
    {
        $stems = self::LEADING_WORDS;

        usort($stems, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        $changed = true;

        while ($changed) {
            $changed = false;
            $lower = mb_strtolower($text);

            foreach ([...$stems, ...self::LEADING_FILLER] as $stem) {
                if ($lower === $stem) {
                    return '';
                }

                if (str_starts_with($lower, $stem.' ')) {
                    $text = trim(mb_substr($text, mb_strlen($stem)));
                    $changed = true;

                    break;
                }
            }
        }

        return $text;
    }

    /**
     * The message's words, punctuation trimmed and anything that is not a
     * plain word dropped.
     *
     * @return array<int, string>
     */
    private static function words(string $text): array
    {
        $words = preg_split('/\s+/u', $text) ?: [];

        $words = array_map(
            static fn (string $word): string => trim($word, " \t\n\r\0\x0B.,;:!?\"'()"),
            $words,
        );

        return array_values(array_filter(
            $words,
            static fn (string $word): bool => $word !== ''
                && preg_match('/^[a-zA-Z0-9+]+(?:-[a-zA-Z0-9]+)*$/', $word) === 1,
        ));
    }

    /**
     * The longest run of two or more consecutive capitalised words, which is
     * how a named statute, programme, or agency shows up in a question.
     *
     * @param  array<int, string>  $words
     */
    private static function properNounRun(array $words): ?string
    {
        $best = [];
        $run = [];

        foreach ($words as $word) {
            // Lowercase connectors keep a name together ("Department of
            // Agrarian Reform") without starting a run of their own.
            $isConnector = $run !== [] && in_array(mb_strtolower($word), ['of', 'and', 'for', 'the'], true);

            if (preg_match('/^\p{Lu}/u', $word) === 1 || $isConnector) {
                $run[] = $word;

                if (count($run) > count($best)) {
                    $best = $run;
                }

                continue;
            }

            $run = [];
        }

        // Trim a trailing connector left dangling by the loop above.
        while ($best !== [] && in_array(mb_strtolower(end($best)), ['of', 'and', 'for', 'the'], true)) {
            array_pop($best);
        }

        return count($best) >= 2 ? implode(' ', $best) : null;
    }

    private static function clip(string $topic, bool $preserveCase): string
    {
        $topic = $preserveCase ? $topic : ucfirst(mb_strtolower($topic));

        return mb_strimwidth($topic, 0, 60, '…');
    }

    /**
     * Match a legal citation (Republic Act, Presidential Decree, Batas
     * Pambansa, Executive Order, or a law code name) in the message.
     */
    private static function citation(string $text): ?string
    {
        if (preg_match('/\b(?:R\.?\s*A\.?\s*|Republic\s+Act\s*(?:No\.?\s*)?)(\d{2,})/i', $text, $matches)) {
            return 'RA '.$matches[1];
        }

        if (preg_match('/\b(?:P\.?\s*D\.?\s*|Presidential\s+Decree\s*(?:No\.?\s*)?)(\d{2,})/i', $text, $matches)) {
            return 'PD '.$matches[1];
        }

        if (preg_match('/\b(?:B\.?\s*P\.?\s*|Batas\s+Pambansa\s*(?:Blg\.?\s*)?)(\d{2,})/i', $text, $matches)) {
            return 'BP Blg. '.$matches[1];
        }

        if (preg_match('/\b(?:E\.?\s*O\.?\s*|Executive\s+Order\s*(?:No\.?\s*)?)(\d{2,})/i', $text, $matches)) {
            return 'EO '.$matches[1];
        }

        if (preg_match('/\b(?:new\s+)?civil code\b/i', $text) === 1) {
            return 'Civil Code';
        }

        if (preg_match('/\bfamily code\b/i', $text) === 1) {
            return 'Family Code';
        }

        if (preg_match('/\blabor code\b/i', $text) === 1) {
            return 'Labor Code';
        }

        return null;
    }
}

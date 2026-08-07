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
     * Build the activity label shown for a streaming step given the user's
     * message, so each step reads as if it were written for that question
     * instead of a generic status. Falls back to a neutral label when no
     * topic can be derived.
     */
    public static function label(string $status, string $question): string
    {
        $topic = self::topic($question);

        return match ($status) {
            'checking_sources' => $topic !== null
                ? "Checking legal sources about {$topic}"
                : 'Checking legal sources',
            'searching_web' => $topic !== null
                ? "Searching the web for more on {$topic}"
                : 'Searching the web for more information',
            'composing' => $topic !== null
                ? "Composing your answer about {$topic}"
                : 'Composing your answer',
            default => Str::headline($status),
        };
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

        $lower = mb_strtolower($text);

        foreach (self::LEADING_WORDS as $stem) {
            if ($lower === $stem || str_starts_with($lower, $stem.' ')) {
                $text = trim(mb_substr($text, mb_strlen($stem)));

                break;
            }
        }

        $words = preg_split('/\s+/u', $text) ?: [];

        $words = array_values(array_filter($words, static function (string $word): bool {
            $clean = trim($word, " \t\n\r\0\x0B.,;:!?\"'()");

            return $clean !== '' && preg_match('/^[a-zA-Z0-9+]+(?:-[a-zA-Z0-9]+)*$/', $clean) === 1;
        }));

        $words = array_slice($words, 0, 5);

        if ($words === []) {
            return null;
        }

        $topic = implode(' ', $words);

        return mb_strimwidth(ucfirst(mb_strtolower($topic)), 0, 60, '…');
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

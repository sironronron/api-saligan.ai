<?php

namespace App\Support;

/**
 * Assigns the short, stable citation tokens the model cites inline and the
 * UI uses to link badges to source cards.
 *
 * Each retrieved source (a legal crawled page or an uploaded document) is a
 * labeled document headed by a token: legal pages use `[SRC <token>]` and
 * uploaded documents use `[DOC <token>]`. Tokens are derived deterministically
 * from the source's row id, so the same source always carries the same token —
 * the model copies it from the block header, and the parser recomputes it
 * from the same identity without any position mapping.
 *
 * Tokens are assigned across the whole set of identities in play (so a page
 * id and a document id can never share a token) and lengthened on collision,
 * so they are collision-free within a retrieval set.
 */
final class CitationTokens
{
    public const SRC = 'SRC';

    public const DOC = 'DOC';

    /**
     * Assign a token to every source identity in play, deterministically.
     * Identities are sorted before assignment so the prompt and the parser
     * produce identical tokens for the same retrieval set regardless of the
     * order the chunks were retrieved in.
     *
     * @param  array<int, string>  $identities  e.g. crawled_page_id / document_id
     * @return array<string, string> identity => token
     */
    public static function assign(array $identities): array
    {
        $unique = array_values(array_unique(array_filter($identities, fn (string $identity): bool => $identity !== '')));

        sort($unique, SORT_STRING);

        $tokens = [];
        $used = [];

        foreach ($unique as $identity) {
            $length = 4;

            do {
                $token = self::hash($identity, $length);
                $length++;
            } while (isset($used[$token]));

            $used[$token] = true;
            $tokens[$identity] = $token;
        }

        return $tokens;
    }

    /**
     * The inline marker the model writes and the parser reads.
     */
    public static function marker(string $kind, string $token): string
    {
        return "[{$kind} {$token}]";
    }

    /**
     * A short uppercase alphanumeric token derived from the identity. Longer
     * tokens are prefixes of shorter ones, so lengthening on collision keeps
     * every already-assigned token unchanged.
     */
    private static function hash(string $identity, int $length): string
    {
        return strtoupper(substr(base_convert(substr(md5($identity), 0, 8), 16, 36), 0, $length));
    }
}

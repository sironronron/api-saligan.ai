<?php

namespace App\Support;

/**
 * Reads a tool's arguments as data that might be anything.
 *
 * A tool schema is a request, not a contract. Providers deliver arguments that
 * the schema says are impossible: a string where an array was declared, an
 * object where a list was, a number for an enum, `null` for a required field,
 * a hundred items where four were asked for. Under load and on smaller models
 * it happens routinely — and every tool here was reading those arguments as if
 * the schema had been honoured. `$item['title']` on an item with no title is
 * an undefined-key error inside a stream that has already sent half an answer;
 * the user sees the turn die at the point it was about to become useful.
 *
 * So nothing is read directly any more. Everything comes through here, where
 * the wrong type is a miss rather than a crash, enums collapse to a known
 * value, and lists are bounded before they reach the database.
 */
final class ToolInput
{
    /**
     * A list of item arrays from a tool argument.
     *
     * Tolerates the two shapes providers substitute for a list: a single item
     * passed unwrapped, and an object keyed by index. Anything that is not an
     * array of arrays after that is dropped rather than guessed at.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function items(mixed $value, int $limit): array
    {
        if (! is_array($value)) {
            return [];
        }

        // A single item sent unwrapped: `{"title": "..."}` instead of
        // `[{"title": "..."}]`. Common on models that flatten one-element lists.
        if (! array_is_list($value)) {
            $value = self::looksLikeItem($value) ? [$value] : array_values($value);
        }

        $items = [];

        foreach ($value as $item) {
            if (count($items) >= $limit) {
                break;
            }

            if (is_array($item)) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * A trimmed string from an item's key, or '' when it is absent or is
     * something a string cannot be made of.
     *
     * Numbers and booleans are accepted — a model answering a text field with
     * `2024` means the string "2024" — but arrays and objects are not, since
     * any rendering of those would be JSON leaking into a user-facing field.
     */
    public static function text(array $item, string $key, int $maxLength = 500): string
    {
        $value = $item[$key] ?? null;

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return '';
        }

        $text = trim((string) $value);

        // Collapse the newlines and runs of whitespace a model puts in a field
        // meant to be one line, so a stored title cannot break the layout it
        // is rendered into.
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return mb_strimwidth($text, 0, $maxLength, '…');
    }

    /**
     * Multi-line text, for the fields where line breaks are meaningful.
     */
    public static function multilineText(array $item, string $key, int $maxLength = 2000): string
    {
        $value = $item[$key] ?? null;

        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return '';
        }

        $text = trim((string) preg_replace('/\R{3,}/u', "\n\n", (string) $value));

        return mb_strimwidth($text, 0, $maxLength, '…');
    }

    /**
     * One of a known set of values, case- and whitespace-insensitively, or the
     * fallback.
     *
     * @param  array<int, string>  $allowed
     */
    public static function enum(array $item, string $key, array $allowed, string $fallback): string
    {
        $value = mb_strtolower(trim((string) ($item[$key] ?? '')));

        // Models write enum values with the separators they saw in the prose
        // around them ("on going", "on_going" for "on-going").
        $value = (string) preg_replace('/[\s_]+/u', '-', $value);

        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    /**
     * A boolean from a value that may be a real boolean, a string, or a number.
     */
    public static function bool(array $item, string $key, bool $fallback = false): bool
    {
        $value = $item[$key] ?? null;

        if ($value === null) {
            return $fallback;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value != 0;
        }

        if (is_string($value)) {
            return in_array(mb_strtolower(trim($value)), ['true', '1', 'yes', 'y', 'on'], true);
        }

        return $fallback;
    }

    /**
     * A list of distinct non-empty strings, for option lists and the like.
     *
     * @return array<int, string>
     */
    public static function strings(mixed $value, int $limit = 20, int $maxLength = 120): array
    {
        if (is_string($value)) {
            // A model asked for a list of options sometimes writes them as one
            // comma-separated string.
            $value = explode(',', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $entry) {
            if (count($strings) >= $limit) {
                break;
            }

            // An option given as `{"label": "..."}` rather than as a string.
            if (is_array($entry)) {
                $entry = $entry['label'] ?? $entry['value'] ?? null;
            }

            $text = self::text(['v' => $entry], 'v', $maxLength);

            if ($text !== '' && ! in_array($text, $strings, true)) {
                $strings[] = $text;
            }
        }

        return $strings;
    }

    /**
     * Whether an associative array looks like one item rather than a keyed
     * collection of them — i.e. its values are scalars, not further arrays.
     *
     * @param  array<array-key, mixed>  $value
     */
    private static function looksLikeItem(array $value): bool
    {
        foreach ($value as $entry) {
            if (is_array($entry)) {
                return false;
            }
        }

        return $value !== [];
    }
}

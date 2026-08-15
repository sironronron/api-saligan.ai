<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * The wire shape of an ask_user_question call.
 *
 * The model's arguments are never sent to the client as they arrive. A question
 * with one option is not a choice, a duplicated label is not a distinct choice,
 * and a model-authored "Other" collides with the escape hatch the UI already
 * offers — so the arguments are normalized here, once, and the client renders
 * only what survives.
 */
final class ChoicePrompt
{
    /**
     * The prefix the client puts on the message carrying the user's selection,
     * so the model (and the status labels) can tell an answer from a fresh
     * question.
     */
    public const SUBMISSION_MARKER = '[Choice Selection]';

    /** More decisions than this in one breath is an interrogation, not a choice. */
    private const MAX_QUESTIONS = 4;

    private const MIN_OPTIONS = 2;

    private const MAX_OPTIONS = 4;

    /**
     * Labels the model sometimes adds itself. The UI always offers "Other" with
     * a free-text box, so a duplicate of it inside the options list is dropped
     * rather than rendered twice.
     *
     * @var array<int, string>
     */
    private const RESERVED_LABELS = [
        'other', 'others', 'something else', 'none of these', 'none of the above',
        'not sure', 'neither', 'let me explain', 'custom',
    ];

    /**
     * Whether the message carries the user's answer to an earlier
     * ask_user_question call.
     */
    public static function isSubmission(string $message): bool
    {
        return str_starts_with($message, self::SUBMISSION_MARKER);
    }

    /**
     * Reduce the model's `questions` argument to the questions actually worth
     * putting to the user. Anything malformed is dropped, never repaired into
     * a question the model did not ask.
     *
     * @param  mixed  $questions  the raw tool argument
     * @return array<int, array{id: string, question: string, header: string, multi_select: bool, options: array<int, array{label: string, description: string}>}>
     */
    public static function normalize(mixed $questions): array
    {
        if (! is_array($questions)) {
            return [];
        }

        $normalized = [];
        $seenIds = [];

        foreach ($questions as $index => $question) {
            if (! is_array($question)) {
                continue;
            }

            $text = self::text($question['question'] ?? null, 300);
            $options = self::options($question['options'] ?? null);

            // A question with nothing to pick between is a dead end in exactly
            // the way this tool exists to prevent, so it is dropped and the
            // model's prose answer stands instead.
            if ($text === '' || count($options) < self::MIN_OPTIONS) {
                continue;
            }

            $header = self::text($question['header'] ?? null, 24);

            $id = self::id($header !== '' ? $header : $text, $index);

            // Two questions sharing an id would overwrite each other's answer
            // in the payload the client sends back.
            if (isset($seenIds[$id])) {
                $id .= '-'.$index;
            }

            $seenIds[$id] = true;

            $normalized[] = [
                'id' => $id,
                'question' => $text,
                'header' => $header !== '' ? $header : Str::limit($text, 20, ''),
                'multi_select' => filter_var($question['multi_select'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'options' => $options,
            ];

            if (count($normalized) === self::MAX_QUESTIONS) {
                break;
            }
        }

        return $normalized;
    }

    /**
     * The choices for one question: labelled, distinct, and free of the
     * model's own "Other".
     *
     * @return array<int, array{label: string, description: string}>
     */
    private static function options(mixed $options): array
    {
        if (! is_array($options)) {
            return [];
        }

        $normalized = [];
        $seenLabels = [];

        foreach ($options as $option) {
            if (! is_array($option)) {
                continue;
            }

            $label = self::text($option['label'] ?? null, 80);

            if ($label === '') {
                continue;
            }

            $key = mb_strtolower($label);

            if (isset($seenLabels[$key]) || in_array($key, self::RESERVED_LABELS, true)) {
                continue;
            }

            $seenLabels[$key] = true;

            $normalized[] = [
                'label' => $label,
                'description' => self::text($option['description'] ?? null, 160),
            ];

            if (count($normalized) === self::MAX_OPTIONS) {
                break;
            }
        }

        return $normalized;
    }

    /**
     * A trimmed, length-capped scalar, or an empty string for anything that is
     * not usable text.
     */
    private static function text(mixed $value, int $limit): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        $text = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');

        return $text === '' ? '' : Str::limit($text, $limit, '');
    }

    /**
     * A stable key for one question, used by the client to hold the answer.
     */
    private static function id(string $source, int $index): string
    {
        $slug = Str::slug(Str::limit($source, 40, ''));

        return $slug !== '' ? $slug : 'question-'.($index + 1);
    }
}

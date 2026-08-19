<?php

namespace App\Services\Chat;

use App\Models\Advisory;
use App\Support\ToolInput;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The one place advisories are written, whether the model filed them through
 * flag_advisories or the server recovered them from the reply text.
 *
 * The guards live here rather than in the tool because the fallback path needs
 * them just as much — arguably more, since prose is a looser source than a
 * typed tool call.
 */
class AdvisoryRecorder
{
    /**
     * More flags than this on one turn is a model itemising the whole answer
     * rather than naming what the reader would otherwise miss — and a list
     * that long is scrolled past exactly like the prose it replaced.
     */
    private const MAX_ITEMS = 12;

    /**
     * Record a batch of flags, dropping boilerplate and anything this
     * conversation has already raised.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>> The rows actually created.
     */
    public function record(string $conversationId, array $items): array
    {
        // Every title already on this conversation, normalized. The prompt
        // carries an ALREADY FLAGGED list, but a caveat re-raised on every turn
        // would bury the ones that are actually new, so the guarantee is made
        // here rather than left to the model's compliance.
        $seen = Advisory::query()
            ->where('conversation_id', $conversationId)
            ->pluck('title')
            ->map(fn (string $title): string => $this->comparisonKey($title))
            ->all();

        $created = [];
        $order = 0;

        foreach (ToolInput::items($items, self::MAX_ITEMS) as $item) {
            // Read defensively: `kind` and `severity` are database enums, so a
            // value outside their sets is a constraint violation that takes
            // down the turn rather than a bad row.
            $title = ToolInput::text($item, 'title', 255);

            if ($title === '') {
                continue;
            }

            if ($this->isBoilerplate($title)) {
                Log::info('Discarded boilerplate advisory', [
                    'conversation_id' => $conversationId,
                    'title' => $title,
                ]);

                continue;
            }

            $key = $this->comparisonKey($title);

            if (in_array($key, $seen, true)) {
                continue;
            }

            $seen[] = $key;

            try {
                $advisory = Advisory::create([
                    'conversation_id' => $conversationId,
                    'kind' => ToolInput::enum($item, 'kind', Advisory::KINDS, 'caveat'),
                    'title' => $title,
                    'detail' => ToolInput::multilineText($item, 'detail', 1000) ?: null,
                    'severity' => ToolInput::enum($item, 'severity', Advisory::SEVERITIES, 'medium'),
                    'order' => $order++,
                ]);
            } catch (Throwable $exception) {
                // One flag failing costs that flag, never the answer it was
                // filed against.
                Log::warning('Could not record an advisory', [
                    'conversation_id' => $conversationId,
                    'title' => $title,
                    'exception' => $exception->getMessage(),
                ]);

                continue;
            }

            $created[] = [
                'id' => $advisory->id,
                'kind' => $advisory->kind,
                'title' => $advisory->title,
                'severity' => $advisory->severity,
            ];
        }

        return $created;
    }

    /**
     * A normalized form of a title, for deciding whether two flags say the same
     * thing. Case, punctuation, and spacing vary between turns even when the
     * point does not.
     */
    public function comparisonKey(string $title): string
    {
        return trim((string) preg_replace('/\s+/', ' ', mb_strtolower(
            (string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $title)
        )));
    }

    /**
     * Whether the flag is a generic disclaimer rather than a point about this
     * matter. The prompt already forbids these, but one that slips through is
     * shown to the user as something real that needs their answer — and a list
     * of "consult a lawyer" items would teach them to ignore the whole feature.
     *
     * Deliberately narrow. A long title carries specifics even when it mentions
     * a lawyer's review ("The retention-limit computation under DAR AO 2 turns
     * on the date of coverage and should be reviewed by your lawyer before
     * filing"), so only short titles are eligible to be dropped at all.
     */
    public function isBoilerplate(string $title): bool
    {
        $key = $this->comparisonKey($title);

        if (mb_strlen($key) > 120) {
            return false;
        }

        foreach ([
            '/\b(consult|seek|engage|retain|obtain)\b[^.]{0,40}\b(lawyer|attorney|counsel|legal advice|legal opinion)\b/',
            '/\bnot (a substitute for|legal advice|intended as legal advice)\b/',
            '/\blaws?( and regulations)?( may| can| could| are subject to)? (change|be amended|be superseded)\b/',
            '/\bthis (is|analysis is|answer is|response is|document is) (general|for information)/',
            '/\b(should|must) be reviewed by (a|an|your) (licensed )?(lawyer|attorney|counsel)\b/',
            '/\bverify (the )?(information|details|accuracy)\b(?![^.]*\b(date|period|number|title|tct|cloa|receipt|deadline)\b)/',
        ] as $pattern) {
            if (preg_match($pattern, $key) === 1) {
                return true;
            }
        }

        return false;
    }
}

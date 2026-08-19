<?php

namespace App\Support;

/**
 * What a tool tells the model when it is done.
 *
 * Tools used to answer with whatever shape suited them, and — more damagingly
 * — to say nothing at all when they silently dropped half of what they were
 * given. A model that passes five tasks and is told "ok" writes "I've added
 * five tasks to your list" under an answer that has three. The reply then
 * contradicts the app, which is the exact failure the tool-claim guard exists
 * to catch after the fact; it is far better not to create it.
 *
 * So every tool answers in one shape: what was accepted, what was rejected and
 * why, and a directive telling the model what to say about it. The rejection
 * count is the important half — it is the only way the model can know its
 * reply must not promise what the tool refused.
 */
final class ToolResult
{
    /**
     * A successful call.
     *
     * @param  array<string, mixed>  $payload  What the tool produced.
     * @param  array<int, string>  $rejected  One line per input that was refused.
     */
    public static function ok(array $payload, array $rejected = [], ?string $directive = null): string
    {
        if ($rejected !== []) {
            $payload['rejected'] = $rejected;
            $payload['rejected_count'] = count($rejected);
        }

        // Said in the imperative because it is read as an instruction, not as
        // information: a model given a bare count will still round it up in
        // the prose.
        $payload['directive'] = $directive ?? ($rejected === []
            ? 'Done. Describe only what this result actually contains — do not inflate the count or add items it does not list.'
            : 'Partially done. '.count($rejected).' item(s) were refused and do NOT exist. Your reply must describe '
                .'only the accepted items, and must not claim the refused ones were created.');

        return self::encode($payload);
    }

    /**
     * A call that produced nothing, with the reason and what to do instead.
     *
     * Never phrased as an error: a model told "the tool failed" apologises to
     * the user about internal machinery they cannot act on. It is told what is
     * now true and what to write instead.
     */
    public static function none(string $reason, string $directive): string
    {
        return self::encode([
            'accepted' => 0,
            'reason' => $reason,
            'directive' => $directive,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function encode(array $payload): string
    {
        return (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

<?php

namespace App\Support;

/**
 * The record of how a chat turn was produced: the steps it went through, how
 * long it took, and how many web sources it read.
 *
 * Every serious assistant now shows its work, and shows it *after* the fact —
 * a line under the answer that expands into what was actually done. That only
 * works if the account outlives the stream. Until now the steps existed solely
 * as SSE frames held in the client's turn object, so the moment the thread was
 * re-fetched from the server the whole history of the answer vanished and the
 * reader was left with prose that had appeared from nowhere.
 *
 * So the steps are recorded here as the controller emits them, persisted onto
 * the assistant message, and handed back with it forever after. Only labels
 * the server itself wrote are kept — never a word the model chose — so the
 * account of the work cannot become another surface for the model to narrate
 * into.
 */
final class TurnActivity
{
    /**
     * Steps in the order they were entered, keyed by status so a status raised
     * twice (composing, interrupted by a search, resumed) is one step.
     *
     * @var array<string, string>
     */
    protected array $steps = [];

    protected ?float $startedAt = null;

    protected int $webSources = 0;

    /**
     * Begin timing a turn and clear anything left from the last one.
     */
    public function start(): void
    {
        $this->steps = [];
        $this->startedAt = microtime(true);
        $this->webSources = 0;
    }

    /**
     * Note that a step was entered. The first label a status is seen with
     * wins: labels are derived from the question and do not change within a
     * turn, and re-labelling a step the reader has already seen reads as the
     * assistant changing its story.
     */
    public function step(string $status, string $label): void
    {
        if ($status === '' || $label === '') {
            return;
        }

        $this->steps[$status] ??= $label;
    }

    public function countWebSources(int $count): void
    {
        $this->webSources = max($this->webSources, $count);
    }

    /**
     * The activity as it is persisted on the message, or null when the turn
     * did nothing worth reporting — a single step is the answer being written,
     * which the answer itself already demonstrates.
     *
     * @return array{steps: array<int, array{key: string, label: string}>, duration_ms: int, web_sources: int}|null
     */
    public function toArray(): ?array
    {
        if (count($this->steps) < 2 && $this->webSources === 0) {
            return null;
        }

        $steps = [];

        foreach ($this->steps as $key => $label) {
            $steps[] = ['key' => $key, 'label' => $label];
        }

        return [
            'steps' => $steps,
            'duration_ms' => $this->startedAt === null
                ? 0
                : (int) round((microtime(true) - $this->startedAt) * 1000),
            'web_sources' => $this->webSources,
        ];
    }
}

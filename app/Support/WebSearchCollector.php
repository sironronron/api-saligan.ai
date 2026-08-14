<?php

namespace App\Support;

/**
 * Per-turn record of the web sources the delegated web search tool grounded an
 * answer in.
 *
 * The provider-native web search surfaces its sources as stream events, which
 * the controller renders as cards and the service persists onto the message.
 * A delegated search has no such events: the search runs inside a tool call,
 * against a different provider than the one streaming the answer, so its
 * sources would otherwise reach the model and nowhere else. This collector is
 * the shared place both paths read from — the tool records what it found, the
 * controller drains new entries to emit them live, and the service reads the
 * whole set when persisting the message.
 *
 * Numbering is assigned here, once, in first-seen order. The number handed
 * back to the model for its "[Web N]" marker is therefore the same number the
 * card is rendered with, both live and on reload.
 */
final class WebSearchCollector
{
    /**
     * Sources seen this turn, keyed by url, in first-seen order.
     *
     * @var array<string, array{url: string, title: string|null, snippet?: string|null}>
     */
    protected array $seen = [];

    /**
     * Urls recorded but not yet drained for live emission.
     *
     * @var array<int, string>
     */
    protected array $pending = [];

    /**
     * Record the sources one search returned and get them back numbered.
     *
     * A url seen by an earlier search in the same turn keeps its original
     * number rather than being counted twice, so repeated searches over
     * overlapping sources do not renumber the cards mid-answer.
     *
     * @param  array<int, array{url: string, title: string|null, snippet?: string|null}>  $citations
     * @return array<int, array{index: int, url: string, title: string|null}>
     */
    public function record(array $citations): array
    {
        $numbered = [];

        foreach ($citations as $citation) {
            $url = $citation['url'] ?? null;

            if (! is_string($url) || $url === '') {
                continue;
            }

            if (! isset($this->seen[$url])) {
                $this->seen[$url] = $citation;
                $this->pending[] = $url;
            } elseif (($this->seen[$url]['snippet'] ?? null) === null && isset($citation['snippet'])) {
                $this->seen[$url]['snippet'] = $citation['snippet'];
            }

            $numbered[] = [
                'index' => $this->indexOf($url),
                'url' => $url,
                'title' => $this->seen[$url]['title'] ?? null,
            ];
        }

        return $numbered;
    }

    /**
     * Drain the sources recorded since the last call, for live emission.
     *
     * @return array<int, array{url: string, title: string|null, snippet?: string|null}>
     */
    public function pull(): array
    {
        $drained = array_map(fn (string $url) => $this->seen[$url], $this->pending);

        $this->pending = [];

        return $drained;
    }

    /**
     * Every source recorded this turn, in first-seen order.
     *
     * @return array<int, array{url: string, title: string|null, snippet?: string|null}>
     */
    public function all(): array
    {
        return array_values($this->seen);
    }

    /**
     * How many distinct sources have been recorded this turn.
     */
    public function count(): int
    {
        return count($this->seen);
    }

    /**
     * The 1-based card number a url was assigned.
     */
    protected function indexOf(string $url): int
    {
        return (int) array_search($url, array_keys($this->seen), true) + 1;
    }
}

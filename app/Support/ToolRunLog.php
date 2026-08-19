<?php

namespace App\Support;

/**
 * The tools that actually ran during one chat turn.
 *
 * The model is the only thing that knows what it *says* it did; this is the
 * record of what it *did*. The two diverge often enough — a reply that opens
 * "I searched the web and found..." on a turn where no search was ever issued
 * is the single most damaging hallucination in a legal product — that the
 * claim has to be checkable against something. This is that something.
 *
 * Written by the controller as it observes tool events on the wire, read by
 * ChatService when it persists the reply and by ToolClaimGuard when it decides
 * whether the reply's account of itself is true.
 */
final class ToolRunLog
{
    /**
     * Tool names called this turn, in first-call order.
     *
     * @var array<int, string>
     */
    protected array $called = [];

    /**
     * Tool names that returned a result this turn.
     *
     * @var array<int, string>
     */
    protected array $completed = [];

    public function recordCall(string $name): void
    {
        if ($name !== '' && ! in_array($name, $this->called, true)) {
            $this->called[] = $name;
        }
    }

    public function recordResult(string $name): void
    {
        $this->recordCall($name);

        if ($name !== '' && ! in_array($name, $this->completed, true)) {
            $this->completed[] = $name;
        }
    }

    public function called(string $name): bool
    {
        return in_array($name, $this->called, true);
    }

    /**
     * Whether the tool ran to completion. A call the provider cut short (the
     * controller breaks the stream on an intake form or a question) is a call,
     * not a result, and cannot support a claim about what the tool found.
     */
    public function completed(string $name): bool
    {
        return in_array($name, $this->completed, true);
    }

    /**
     * @return array<int, string>
     */
    public function all(): array
    {
        return $this->called;
    }

    public function reset(): void
    {
        $this->called = [];
        $this->completed = [];
    }
}

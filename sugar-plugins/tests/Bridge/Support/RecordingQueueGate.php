<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Tests\Bridge\Support;

use SugarCraft\Plugins\Bridge\QueueGate;

/**
 * A QueueGate that holds pushed items until the test flushes them, the way a
 * closed debounce window delivers one batch to the most recent flush closure.
 */
final class RecordingQueueGate implements QueueGate
{
    /** @var list<string> keys pushed, in order */
    public array $pushedKeys = [];

    /** @var list<string> text pushed, in order */
    public array $pushedText = [];

    /** @var list<string> items waiting for the next flush */
    private array $items = [];

    /** @var callable(list<string>): void|null */
    private $flusher = null;

    public function __construct(public int $dropped = 0)
    {
    }

    public function push(string $key, string $text, callable $flush): int
    {
        $this->pushedKeys[] = $key;
        $this->pushedText[] = $text;
        $this->items[] = $text;
        $this->flusher = $flush;

        return $this->dropped;
    }

    /** Deliver everything held so far as one batch. */
    public function flush(): void
    {
        if ($this->flusher === null || $this->items === []) {
            return;
        }
        $items = $this->items;
        $this->items = [];
        ($this->flusher)($items);
    }
}

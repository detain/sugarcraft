<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents\Live;

/**
 * The child-side coalescer behind a delegated run's v2 activity frames
 * (Appendix P §4.3): items accumulate here and leave in batches, so a busy
 * sub-agent costs the parent at most {@see FRAMES_PER_SECOND} frames a second
 * however many tools it calls.
 *
 * FLUSH RULES. {@see due()} is true once the buffer holds something and
 * {@see FLUSH_INTERVAL_SECONDS} have passed since the last flush. The caller
 * asks on every callback the run makes (tool events, reasoning, the
 * transport heartbeat, each billed step), so an item never waits much longer
 * than the interval; the run's started and finished frames flush whatever is
 * pending regardless. The design's "flush a tool_started within 100 ms" fast
 * path is deliberately folded into the same interval: it would let a burst of
 * quick calls exceed the 4 Hz ceiling the frame budget is built on (§4.6).
 *
 * CAPS. One flush hands out at most {@see MAX_ITEMS} items whose serialised
 * size fits the byte budget the caller passes (by default {@see MAX_BYTES}).
 * Over a cap, the oldest text fragments go first, then the oldest tool
 * events; the newest item survives whenever it fits the budget at all,
 * because it is what the line shows.
 *
 * COALESCING. Consecutive text deltas merge into one item (keeping its newest
 * {@see ActivityItem::MAX_TEXT_BYTES}), and a thinking marker right after
 * another thinking marker adds nothing.
 *
 * Deliberately mutable: it is the run's own stack-local state, like the
 * closures it replaced, never shared and never rendered.
 */
final class SubAgentActivityBuffer
{
    public const FLUSH_INTERVAL_SECONDS = 0.25;

    public const FRAMES_PER_SECOND = 4;

    public const MAX_ITEMS = 32;

    public const MAX_BYTES = 8192;

    /** @var list<ActivityItem> */
    private array $items = [];

    private ?float $lastFlush = null;

    /**
     * @param \Closure(): float $clock seconds, monotonic enough for intervals
     */
    private function __construct(private \Closure $clock)
    {
    }

    /**
     * @param (\Closure(): float)|null $clock null uses microtime(true); a
     *        test passes its own to drive the interval deterministically
     */
    public static function new(?\Closure $clock = null): self
    {
        return new self($clock ?? static fn (): float => microtime(true));
    }

    public function add(ActivityItem $item): void
    {
        $last = $this->items === [] ? null : $this->items[array_key_last($this->items)];

        if ($last !== null && $last->type === ActivityItem::TEXT && $item->type === ActivityItem::TEXT) {
            $this->items[array_key_last($this->items)] = $last->withMoreText($item->delta);

            return;
        }

        if ($last !== null && $last->type === ActivityItem::THINKING && $item->type === ActivityItem::THINKING) {
            return;
        }

        $this->items[] = $item;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * Whether an activity frame should go out now.
     */
    public function due(): bool
    {
        if ($this->items === []) {
            return false;
        }

        return $this->lastFlush === null
            || ($this->clock)() - $this->lastFlush >= self::FLUSH_INTERVAL_SECONDS;
    }

    /**
     * Hand out everything pending, capped, and start the next interval.
     * Called for activity frames when {@see due()}, and unconditionally for
     * the run's started and finished frames.
     *
     * @param int $maxBytes the serialised-size budget for this frame's items
     *
     * @return list<ActivityItem>
     */
    public function flush(int $maxBytes = self::MAX_BYTES): array
    {
        $items = $this->items;
        $this->items = [];
        $this->lastFlush = ($this->clock)();

        return self::capped($items, max(0, $maxBytes));
    }

    /**
     * @param list<ActivityItem> $items
     *
     * @return list<ActivityItem>
     */
    private static function capped(array $items, int $maxBytes): array
    {
        while (count($items) > 1 && (count($items) > self::MAX_ITEMS || self::bytes($items) > $maxBytes)) {
            $items = self::dropOldest($items);
        }

        if ($items !== [] && self::bytes($items) > $maxBytes) {
            return [];
        }

        return $items;
    }

    /**
     * Drop the oldest text fragment; when there is none, the oldest item of
     * any other kind. Never the newest.
     *
     * @param list<ActivityItem> $items
     *
     * @return list<ActivityItem>
     */
    private static function dropOldest(array $items): array
    {
        $newest = count($items) - 1;
        foreach ($items as $index => $item) {
            if ($index < $newest && $item->type === ActivityItem::TEXT) {
                array_splice($items, $index, 1);

                return $items;
            }
        }

        array_splice($items, 0, 1);

        return $items;
    }

    /**
     * @param list<ActivityItem> $items
     */
    private static function bytes(array $items): int
    {
        return strlen(serialize(array_map(static fn (ActivityItem $item): array => $item->toArray(), $items)));
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host;

use React\EventLoop\LoopInterface;
use SugarCraft\Code\Protocol\EventEnvelope;
use SugarCraft\Code\Protocol\EventType;
use SugarCraft\Code\Server\Workspace\Gateway;
use SugarCraft\Code\Server\Workspace\RelayTarget;

/**
 * The relay target of one in-process client that follows sessions of a non-primary
 * root (PL-5c-A design, section 3). The gateway fans a workspace host's events out to
 * it through {@see RelayTarget::deliver()}; the sink queues them in arrival order and
 * hands them to the client's listeners on the loop, at most {@see DELIVERY_BUDGET} per
 * tick, so a backlog never holds the serve loop.
 *
 * Bounds (section 3.3). The queue holds {@see QUEUE_BOUND} envelopes, and live-only
 * events (deltas) up to {@see LIVE_BYTES_BOUND} encoded bytes. Over a live bound, a delta
 * coalesces into a queued one with the same key, else the oldest live event is shed. Each
 * shed is counted into one `server.overflow` notice per session, placed at the first shed
 * position; the session keeps flowing. A durable event that finds the queue full cannot be
 * shed: the session's queued events from its first undelivered durable event onward are
 * replaced by one notice, the session stops until the client resubscribes, and the gateway
 * cursor is rewound to the last seq the listeners actually received.
 *
 * The cursor is the gateway's (`cursors[id][session]`, the same table the wire uses), so this
 * class keeps none of its own beyond what it has delivered for each session.
 */
final class InProcessEventSink implements RelayTarget
{
    /** The most queued envelopes (notices excluded) before durable overflow. */
    public const QUEUE_BOUND = 2048;

    /** Encoded bytes of queued live-only events before live shedding (the wire's soft watermark). */
    public const LIVE_BYTES_BOUND = 1_048_576;

    /** Envelopes handed to the listeners per loop tick. */
    public const DELIVERY_BUDGET = 64;

    private bool $open = true;

    /**
     * @var list<array{envelope: array<string, mixed>, sessionId: ?string, seq: ?int, live: bool, notice: bool, stop: bool, bytes: int, key: ?string, root: ?string, dropped: int}>
     */
    private array $queue = [];

    private int $queued = 0;

    private int $liveBytes = 0;

    /** @var array<string, true> sessions stopped by a durable overflow, until resubscribed */
    private array $stopped = [];

    /** @var array<string, int> the last durable seq each session delivered to the listeners (or its follow cursor) */
    private array $delivered = [];

    /** @var array<string, true> the sessions this sink follows, in the order it subscribed */
    private array $followed = [];

    private bool $scheduled = false;

    private bool $pumping = false;

    /**
     * @param \Closure(array<string, mixed>): void $fanOut hands one envelope to the client's listeners
     */
    public function __construct(
        private readonly string $id,
        private readonly Gateway $gateway,
        private readonly LoopInterface $loop,
        private readonly \Closure $fanOut,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function isOpen(): bool
    {
        return $this->open;
    }

    /** False for a session stopped by an overflow: its events are discarded before any cursor moves. */
    public function accepts(?string $sessionId): bool
    {
        return $sessionId === null || !isset($this->stopped[$sessionId]);
    }

    /** An in-process client is an owner: it has no viewer pin. */
    public function viewerSessions(): ?array
    {
        return null;
    }

    public function deliver(?array $message, string $text, ?string $coalesceKey, bool $ephemeral, ?int $seq, ?string $sessionId): void
    {
        $params = $message['params'] ?? null;
        if (!$this->open || !\is_array($params)) {
            return;
        }
        $root = \is_string($params['root'] ?? null) ? $params['root'] : null;
        $bytes = \strlen($text);

        if ($ephemeral) {
            if ($coalesceKey !== null && $this->liveBytes + $bytes > self::LIVE_BYTES_BOUND && $this->coalesce($coalesceKey, $params)) {
                return;
            }
            while ($this->liveBytes + $bytes > self::LIVE_BYTES_BOUND && $this->shedOldestLive()) {
            }
            if ($this->queued >= self::QUEUE_BOUND && !$this->shedOldestLive()) {
                $this->countDrop($sessionId, $root, 1);

                return;
            }
            $this->push($params, $sessionId, $seq, true, $bytes, $coalesceKey, $root);
            $this->schedule();

            return;
        }

        if ($this->queued >= self::QUEUE_BOUND) {
            $this->overflow($sessionId, $root);
            $this->schedule();

            return;
        }
        $this->push($params, $sessionId, $seq, false, $bytes, null, $root);
        $this->schedule();
    }

    /** The gateway no longer forwards $sessionId: stop listing it, keep what is queued (it is delivered before the notice). */
    public function unfollowed(string $sessionId): void
    {
        unset($this->followed[$sessionId], $this->delivered[$sessionId]);
    }

    /**
     * The client subscribed to $sessionId from $cursor: clears any stop, drops the undelivered
     * entries of the session (a replay from the host supplies them again), and sets the cursor.
     */
    public function follow(string $sessionId, int $cursor): void
    {
        unset($this->stopped[$sessionId]);
        $this->purge($sessionId);
        $this->followed[$sessionId] = true;
        $this->delivered[$sessionId] = $cursor;
        $this->gateway->setTargetCursor($this->id, $sessionId, $cursor);
    }

    /** The client unsubscribed from $sessionId: forget it and its queued events. */
    public function unfollow(string $sessionId): void
    {
        unset($this->followed[$sessionId], $this->stopped[$sessionId], $this->delivered[$sessionId]);
        $this->purge($sessionId);
    }

    /** @return list<string> */
    public function followedSessions(): array
    {
        return \array_map('strval', \array_keys($this->followed));
    }

    /**
     * Hand every queued envelope to the listeners now, without the per-tick budget. Used by
     * disconnect(), which delivers what the client is owed before it leaves. A call from inside
     * a delivery does nothing: the running pump delivers the queue in order.
     */
    public function flush(): void
    {
        $this->pump(\PHP_INT_MAX);
    }

    /** The client left: nothing further is delivered, and the queue is dropped. */
    public function close(): void
    {
        $this->open = false;
        $this->queue = [];
        $this->queued = 0;
        $this->liveBytes = 0;
    }

    /**
     * A live event under a key that is already queued merges into it when both carry text
     * (`data.text`), which is what the wire's coalescing does for deltas.
     *
     * @param array<string, mixed> $params
     */
    private function coalesce(string $key, array $params): bool
    {
        $text = $params['data']['text'] ?? null;
        if (!\is_string($text)) {
            return false;
        }
        for ($i = \count($this->queue) - 1; $i >= 0; $i--) {
            $entry = $this->queue[$i];
            if ($entry['notice'] || !$entry['live'] || $entry['key'] !== $key) {
                continue;
            }
            $merged = $entry['envelope'];
            $previous = $merged['data']['text'] ?? null;
            if (!\is_string($previous)) {
                return false;
            }
            $merged['data']['text'] = $previous . $text;
            $bytes = \strlen((string) \json_encode($merged, \JSON_INVALID_UTF8_SUBSTITUTE));
            $this->liveBytes += $bytes - $entry['bytes'];
            $this->queue[$i]['envelope'] = $merged;
            $this->queue[$i]['bytes'] = $bytes;

            return true;
        }

        return false;
    }

    /**
     * Sheds the oldest queued live event; its session's notice takes the event's place, or grows.
     */
    private function shedOldestLive(): bool
    {
        foreach ($this->queue as $i => $entry) {
            if ($entry['notice'] || !$entry['live']) {
                continue;
            }
            $this->liveBytes -= $entry['bytes'];
            $this->queued--;
            unset($this->queue[$i]);
            $this->queue = \array_values($this->queue);
            $this->countDropAt($i, $entry['sessionId'], $entry['root'], 1);

            return true;
        }

        return false;
    }

    /**
     * Adds $count to the session's live notice, inserting one at $index when none is queued
     * (the notice takes the first shed position).
     */
    private function countDropAt(int $index, ?string $sessionId, ?string $root, int $count): void
    {
        foreach ($this->queue as $i => $entry) {
            if ($entry['notice'] && $entry['sessionId'] === $sessionId && !$entry['stop']) {
                $this->queue[$i]['dropped'] += $count;

                return;
            }
        }
        \array_splice($this->queue, $index, 0, [$this->noticeEntry($sessionId, $root, $count, false)]);
    }

    /** Adds $count to the session's live notice at the tail (a shed that had no queued event to replace). */
    private function countDrop(?string $sessionId, ?string $root, int $count): void
    {
        $this->countDropAt(\count($this->queue), $sessionId, $root, $count);
    }

    /**
     * A durable event found the queue full. The session's queued events from its first
     * undelivered durable event onward are replaced by one stop notice, and the cursor is
     * rewound to the last seq the listeners received. Other sessions keep their entries.
     */
    private function overflow(?string $sessionId, ?string $root): void
    {
        if ($sessionId === null) {
            $this->countDrop(null, $root, 1);

            return;
        }
        $cut = null;
        foreach ($this->queue as $i => $entry) {
            if (!$entry['notice'] && $entry['sessionId'] === $sessionId && $entry['seq'] !== null) {
                $cut = $i;
                break;
            }
        }
        $cut ??= \count($this->queue);

        // Pass one counts and removes what goes: the session's queued events from the cut on, and
        // every notice the session has queued (its count moves into the stop notice).
        $dropped = 0;
        foreach ($this->queue as $i => $entry) {
            if ($entry['sessionId'] !== $sessionId || (!$entry['notice'] && $i < $cut)) {
                continue;
            }
            if ($entry['notice']) {
                $dropped += $entry['dropped'];
                continue;
            }
            $dropped++;
            $this->queued--;
            if ($entry['live']) {
                $this->liveBytes -= $entry['bytes'];
            }
        }

        // Pass two keeps the other sessions' entries in order and puts the stop notice at the cut.
        $kept = [];
        $placed = false;
        foreach ($this->queue as $i => $entry) {
            if ($i === $cut) {
                $kept[] = $this->noticeEntry($sessionId, $root, $dropped, true);
                $placed = true;
            }
            if ($entry['sessionId'] === $sessionId && ($entry['notice'] || $i >= $cut)) {
                continue;
            }
            $kept[] = $entry;
        }
        if (!$placed) {
            $kept[] = $this->noticeEntry($sessionId, $root, $dropped, true);
        }
        $this->queue = $kept;
        $this->stopped[$sessionId] = true;
        $this->gateway->rewindCursor($this->id, $sessionId, $this->delivered[$sessionId] ?? 0);
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private function push(array $envelope, ?string $sessionId, ?int $seq, bool $live, int $bytes, ?string $key, ?string $root): void
    {
        $this->queue[] = [
            'envelope' => $envelope,
            'sessionId' => $sessionId,
            'seq' => $seq,
            'live' => $live,
            'notice' => false,
            'stop' => false,
            'bytes' => $bytes,
            'key' => $key,
            'root' => $root,
            'dropped' => 0,
        ];
        $this->queued++;
        if ($live) {
            $this->liveBytes += $bytes;
        }
    }

    /** @return array{envelope: array<string, mixed>, sessionId: ?string, seq: ?int, live: bool, notice: bool, stop: bool, bytes: int, key: ?string, root: ?string, dropped: int} */
    private function noticeEntry(?string $sessionId, ?string $root, int $dropped, bool $stop): array
    {
        return [
            'envelope' => [],
            'sessionId' => $sessionId,
            'seq' => null,
            'live' => false,
            'notice' => true,
            'stop' => $stop,
            'bytes' => 0,
            'key' => null,
            'root' => $root,
            'dropped' => $dropped,
        ];
    }

    /** Removes the undelivered entries of $sessionId, the stop notice included. */
    private function purge(string $sessionId): void
    {
        $kept = [];
        foreach ($this->queue as $entry) {
            if ($entry['sessionId'] === $sessionId) {
                if (!$entry['notice']) {
                    $this->queued--;
                    if ($entry['live']) {
                        $this->liveBytes -= $entry['bytes'];
                    }
                }
                continue;
            }
            $kept[] = $entry;
        }
        $this->queue = $kept;
    }

    /**
     * Arms one delivery tick while events are owed. The first tick is a future tick; a re-armed
     * one is a zero-delay timer, because the future-tick queue drains to empty within the loop
     * iteration that runs it, so re-arming there would deliver the whole backlog in one go.
     */
    private function schedule(bool $again = false): void
    {
        if ($this->scheduled || $this->queue === []) {
            return;
        }
        $this->scheduled = true;
        $tick = function (): void {
            $this->scheduled = false;
            $this->pump(self::DELIVERY_BUDGET);
            $this->schedule(true);
        };
        if ($again) {
            $this->loop->addTimer(0.0, $tick);

            return;
        }
        $this->loop->futureTick($tick);
    }

    /** Delivers up to $budget entries, in queue order. Not re-entrant. */
    private function pump(int $budget): void
    {
        if ($this->pumping) {
            return;
        }
        $this->pumping = true;
        try {
            while ($budget > 0 && $this->open && $this->queue !== []) {
                $budget--;
                $entry = \array_shift($this->queue);
                if ($entry['notice']) {
                    $envelope = $this->overflowEnvelope($entry);
                } else {
                    $this->queued--;
                    if ($entry['live']) {
                        $this->liveBytes -= $entry['bytes'];
                    }
                    if ($entry['seq'] !== null && $entry['sessionId'] !== null) {
                        $this->delivered[$entry['sessionId']] = $entry['seq'];
                    }
                    $envelope = $entry['envelope'];
                }
                ($this->fanOut)($envelope);
            }
        } finally {
            $this->pumping = false;
        }
    }

    /** @param array{envelope: array<string, mixed>, sessionId: ?string, seq: ?int, live: bool, notice: bool, stop: bool, bytes: int, key: ?string, root: ?string, dropped: int} $entry */
    private function overflowEnvelope(array $entry): array
    {
        $envelope = EventEnvelope::server(EventType::SERVER_OVERFLOW, [
            'sessionId' => $entry['sessionId'],
            'dropped' => $entry['dropped'],
            'action' => 'resubscribe',
        ])->toArray();
        if ($entry['root'] !== null) {
            $envelope['root'] = $entry['root'];
        }

        return $envelope;
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host;

use SugarCraft\Crush\Session\EnhancedSessionStore;

/**
 * The durable, per-session event log a host writes before it broadcasts
 * (roadmap O-2b, Appendix O §4.8 / §6.4 / §6.8): one `session_events` row per
 * durable event, numbered by a `seq` that is monotonic per session and never
 * reused.
 *
 * WHY A SEQ AND NOT A TIMESTAMP. A client that drops and reconnects asks for
 * "everything after seq N" and must get exactly what it missed — no gap, no
 * duplicate — whatever the clocks did meanwhile. The seq is allocated inside
 * the store's `BEGIN IMMEDIATE` write transaction as `MAX(seq) + 1`, so two
 * writers on one database cannot take the same number and a restarted
 * process continues where the log stopped. Ephemeral events (token deltas,
 * progress) are never written here and never carry a seq; durable ones are
 * written FIRST, so a broadcast can never get ahead of the log a replay reads.
 *
 * WHY IN SESSION.DB. The log lives beside the transcript it describes, in the
 * same SQLite file, so deleting or pruning a session takes its events with it
 * (foreign-key cascade) and a fork starts its own log at seq 1.
 *
 * BOUNDED. Each append past {@see retain()} events drops the oldest in the
 * same transaction (Appendix O §4.8: 20,000 per session by default). A reader
 * whose cursor fell below {@see oldestSeq()} has lost events and resyncs from
 * a snapshot instead of replaying ({@see isReplayable()}).
 *
 * The events themselves are produced by the `Host\*` services that follow
 * O-2b — the turn runner and transcript projector of O-2f first — and read by
 * the protocol layer's subscribe/replay (O-3b). This class is the storage
 * contract both sides share, registered on the
 * {@see WorkspaceContext::service()} locator under its class name.
 */
final class EventLog
{
    /** Appendix O §4.8's per-session retention cap. */
    public const DEFAULT_RETAIN = 20000;

    /** The replay page size Appendix O §6.8 names. */
    public const PAGE_SIZE = 200;

    private function __construct(
        private readonly EnhancedSessionStore $store,
        private readonly int $retain,
    ) {
    }

    /**
     * A log over $store's `session_events` table.
     *
     * @param int $retain events kept per session; below 1 keeps every event
     */
    public static function new(EnhancedSessionStore $store, int $retain = self::DEFAULT_RETAIN): self
    {
        return new self($store, max(0, $retain));
    }

    /** A copy keeping $retain events per session (below 1 keeps all). */
    public function withRetain(int $retain): self
    {
        return new self($this->store, max(0, $retain));
    }

    /** The store the log writes into. */
    public function store(): EnhancedSessionStore
    {
        return $this->store;
    }

    /** Events kept per session; 0 is unbounded. */
    public function retain(): int
    {
        return $this->retain;
    }

    /**
     * Write one durable event and return its seq.
     *
     * @param string $type an Appendix O §6.5 event type, e.g. `message.created`
     * @param array<string, mixed> $data the event's `data`; must be JSON-encodable
     * @param int|null $tsMs milliseconds since the epoch; null is now
     *
     * @throws \InvalidArgumentException on an empty type
     * @throws \JsonException on data JSON cannot encode
     * @throws \Throwable whatever the database throws: a durable event that
     *                    could not be written must not be broadcast either
     */
    public function append(string $sessionId, string $type, array $data = [], ?int $tsMs = null): int
    {
        return $this->store->appendSessionEvent($sessionId, $type, $data, $tsMs, $this->retain);
    }

    /**
     * One replay page: $sessionId's events after $afterSeq, oldest first, at
     * most $limit.
     *
     * @return list<array{seq: int, ts: int, type: string, payload: array<string, mixed>}>
     */
    public function since(string $sessionId, int $afterSeq = 0, int $limit = self::PAGE_SIZE): array
    {
        return $this->store->sessionEvents($sessionId, $afterSeq, $limit);
    }

    /** The newest seq in $sessionId's log, 0 when it has none. */
    public function latestSeq(string $sessionId): int
    {
        return $this->store->sessionEventBounds($sessionId)[1];
    }

    /** The oldest seq still retained for $sessionId, null when the log is empty. */
    public function oldestSeq(string $sessionId): ?int
    {
        return $this->store->sessionEventBounds($sessionId)[0];
    }

    /**
     * Whether a client holding everything through $afterSeq can catch up by
     * replay (Appendix O §6.8 step 3). False when retention has already
     * dropped an event it never saw, or when the cursor is AHEAD of the log
     * (it belongs to another database); both mean "resync from a snapshot".
     */
    public function isReplayable(string $sessionId, int $afterSeq): bool
    {
        [$oldest, $latest] = $this->store->sessionEventBounds($sessionId);
        if ($afterSeq < 0 || $afterSeq > $latest) {
            return false;
        }

        return $oldest === null || $afterSeq >= $oldest - 1;
    }
}

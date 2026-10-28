<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host;

use SugarCraft\Code\Context\CompactorConfig;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Session\SessionQuery;
use SugarCraft\Code\Session\SessionRow;

/**
 * The open sessions of one workspace, each with its {@see SessionHost}
 * (roadmap O-2g, Appendix O §4.3–§4.5).
 *
 * WHAT IT OWNS. Which sessions are open in this process, the lock each one
 * holds, and how many may be open at once. Opening a session loads its
 * transcript through the workspace's {@see TranscriptStore} and takes the
 * same flock `SessionLock` a TUI takes (§4.8: the lease already exists), so a
 * session another candy-code has open is refused here exactly as the TUI's
 * second window goes read-only — a host never writes a session it does not
 * hold.
 *
 * IDLE EVICTION, NEVER A BUSY ONE (§4.4, §4.5). Past {@see maxOpen()} the
 * least recently used IDLE host is released — its transcript is already
 * saved, so only the in-memory host goes. A host with a turn in flight (or a
 * submission parked behind its hooks) is never evicted, and
 * {@see close()} refuses one unless asked to cancel it.
 *
 * MUTABLE ON PURPOSE: it is the live registry of a server's sessions, the
 * way {@see TurnRunner} is the live registry of its turns.
 */
final class SessionHub
{
    /** Appendix O §4.4's `serverMaxOpenSessions` default. */
    public const DEFAULT_MAX_OPEN = 32;

    /** @var array<string, SessionHost> open hosts, least recently used first */
    private array $hosts = [];

    private function __construct(
        private readonly WorkspaceContext $workspace,
        private readonly int $maxOpen,
        private readonly ?CompactorConfig $compactorConfig,
    ) {
    }

    /**
     * A hub over $workspace keeping at most $maxOpen sessions open.
     *
     * @throws \InvalidArgumentException for a cap below one
     */
    public static function new(
        WorkspaceContext $workspace,
        int $maxOpen = self::DEFAULT_MAX_OPEN,
        ?CompactorConfig $compactorConfig = null,
    ): self {
        if ($maxOpen < 1) {
            throw new \InvalidArgumentException(sprintf('A session hub must allow at least one open session; got %d.', $maxOpen));
        }

        return new self($workspace, $maxOpen, $compactorConfig);
    }

    public function workspace(): WorkspaceContext
    {
        return $this->workspace;
    }

    public function maxOpen(): int
    {
        return $this->maxOpen;
    }

    /**
     * The host of $sessionId: the open one, or a new one over its saved
     * transcript, holding its lock.
     *
     * @throws \RuntimeException when another process holds the session — it
     *         is open in a TUI or another server, and only one may write it
     */
    public function open(string $sessionId): SessionHost
    {
        if (isset($this->hosts[$sessionId])) {
            return $this->touch($sessionId);
        }

        $transcripts = SessionHost::transcriptsFor($this->workspace);
        $lease = null;
        if ($transcripts->persists()) {
            $lease = $transcripts->lock($sessionId);
            if ($lease === null) {
                $holder = $transcripts->lockHolder($sessionId);
                throw new \RuntimeException(Lang::t('host.hub.locked', [
                    'session' => $sessionId,
                    'holder' => $holder === null ? '' : Lang::t('chat.session.lock_holder', ['pid' => $holder]),
                ]));
            }
        }

        $host = SessionHost::new(
            $sessionId,
            $this->workspace,
            $transcripts->load($sessionId),
            $transcripts,
            $lease,
            $this->compactorConfig,
        );
        $this->hosts[$sessionId] = $host;
        $this->evictIdle($sessionId);

        return $host;
    }

    /**
     * A new session, created in the workspace's store when it has one, and
     * opened.
     *
     * @param string|null $sessionId null mints one
     * @param bool $ephemeral C-SES-7.e: an incognito session — no store row,
     *        no lease, and a host that writes nothing back. It rides the
     *        open-host registry until it is closed (or evicted), and then it
     *        is gone: there is nothing durable left behind to re-open.
     */
    public function create(?string $sessionId = null, ?string $name = null, string $provider = '', string $model = '', bool $ephemeral = false): SessionHost
    {
        $sessionId ??= bin2hex(random_bytes(8));
        if (!$ephemeral) {
            $store = $this->workspace->sessionStore;
            if ($store !== null && $store->session($sessionId) === null) {
                $store->createSession($sessionId, $provider, $model, name: $name, cwd: $this->workspace->root);
            }

            return $this->open($sessionId);
        }

        // The lease guards the durable row; with no row there is nothing to
        // lock — and open() would take one — so the host is built directly
        // over an empty history with its name carried on the host itself.
        if (isset($this->hosts[$sessionId])) {
            return $this->touch($sessionId);
        }

        $host = SessionHost::new(
            $sessionId,
            $this->workspace,
            [],
            SessionHost::transcriptsFor($this->workspace),
            compactorConfig: $this->compactorConfig,
            ephemeral: true,
            ephemeralName: $name,
        );
        $this->hosts[$sessionId] = $host;
        $this->evictIdle($sessionId);

        return $host;
    }

    /** The open host of $sessionId, or null. */
    public function get(string $sessionId): ?SessionHost
    {
        return $this->hosts[$sessionId] ?? null;
    }

    public function isOpen(string $sessionId): bool
    {
        return isset($this->hosts[$sessionId]);
    }

    /** @return list<string> the open sessions, least recently used first */
    public function openSessionIds(): array
    {
        return array_map(static fn (int|string $id): string => (string) $id, array_keys($this->hosts));
    }

    /**
     * Close $sessionId: release its lock and forget its host. A host with a
     * turn in flight is refused unless $force, which cancels the turn first
     * (§4.5: detaching a client never stops a turn; closing the session does,
     * and only when asked).
     *
     * @return bool whether a host was closed
     */
    public function close(string $sessionId, bool $force = false): bool
    {
        $host = $this->hosts[$sessionId] ?? null;
        if ($host === null) {
            return false;
        }

        if ($host->isBusy()) {
            if (!$force) {
                return false;
            }
            $host->cancel();
        }

        unset($this->hosts[$sessionId]);
        $host->release();

        return true;
    }

    /** Close every open session, cancelling any turn still running. */
    public function closeAll(): void
    {
        foreach (array_keys($this->hosts) as $sessionId) {
            $this->close((string) $sessionId, force: true);
        }
    }

    /**
     * The workspace's sessions, newest activity first (ranked best-hit first
     * with $query), paged by id cursor.
     *
     * $query switches the read to the SES-2 full-text path — bodies ranked
     * by FTS5 with a snippet on each row — degrading to the name/preview/id
     * LIKE prefilter when the module cannot answer (C-SES-2.c, which replaces
     * the filter-every-page-then-discard loop this method used to run).
     * Cursor paging keeps the same law on both paths: the cursor is the last
     * id this page handed out, and the next page continues where it stopped.
     *
     * @return array{sessions: list<array<string, mixed>>, nextCursor: ?string}
     */
    public function list(int $limit = 20, ?string $cursor = null, ?string $query = null, ?bool $archived = null): array
    {
        $store = $this->workspace->sessionStore;
        if (!$store instanceof EnhancedSessionStore || $limit < 1) {
            return ['sessions' => [], 'nextCursor' => null];
        }

        // C-SES-7.e: $archived selects which archive state to show. null
        // (default) means the ordinary live listing (archived rows hidden);
        // true means the archived tray; false is the same as null today but
        // spelled. Both store paths honour it identically so the cursor law
        // stays one law. (The meta path gained this filter here: it reads
        // `SELECT s.*` and carried archived rows unfiltered before.)
        //
        // One row beyond the page tells whether another page exists; a cursor
        // means reading past everything up to it. The filtered path returns
        // SessionRow OBJECTS (rank order, snippet attached); it is converted
        // through toArray() here, at the hub's array boundary, so callers
        // downstream keep seeing the storage-shaped rows they always did.
        $rows = $query !== null
            ? array_map(
                static fn (SessionRow $row): array => [...$row->toArray(), 'bodySnippet' => $row->bodySnippet],
                $store->listSessionsFiltered(self::archiveQuery($archived)->withBodySearch($query)->withLimit(PHP_INT_MAX)),
            )
            : \array_values(\array_filter(
                $store->listSessionsWithMeta(PHP_INT_MAX),
                static fn (array $row): bool => self::archivedMatches($row, $archived),
            ));
        if ($cursor !== null) {
            $ids = array_map(static fn (array $row): string => (string) ($row['id'] ?? ''), $rows);
            $at = array_search($cursor, $ids, true);
            $rows = $at === false ? [] : \array_slice($rows, (int) $at + 1);
        }

        $page = \array_slice($rows, 0, $limit);
        $more = \count($rows) > $limit;
        $sessions = array_map(fn (array $row): array => [
            ...$row,
            'open' => isset($this->hosts[(string) ($row['id'] ?? '')]),
        ], $page);

        return [
            'sessions' => array_values($sessions),
            'nextCursor' => $more && $page !== [] ? (string) ($page[\count($page) - 1]['id'] ?? '') : null,
        ];
    }

    /**
     * The FTS-shaped query for one archive view: null/false read the live
     * listing (the SessionQuery default already excludes archived rows),
     * true reads the archived tray.
     */
    private static function archiveQuery(?bool $archived): SessionQuery
    {
        return $archived === true
            ? SessionQuery::new()->withArchivedOnly(true)
            : SessionQuery::new();
    }

    /** The meta path's same law, applied to the storage-shaped row. */
    private static function archivedMatches(array $row, ?bool $archived): bool
    {
        $isArchived = ($row['archived_at'] ?? null) !== null;

        return $archived === true ? $isArchived : !$isArchived;
    }

    /** Move $sessionId to the most-recently-used end and return its host. */
    private function touch(string $sessionId): SessionHost
    {
        $host = $this->hosts[$sessionId];
        unset($this->hosts[$sessionId]);
        $this->hosts[$sessionId] = $host;

        return $host;
    }

    /** Release least-recently-used idle hosts until the cap holds, never $keep. */
    private function evictIdle(string $keep): void
    {
        foreach ($this->hosts as $sessionId => $host) {
            if (\count($this->hosts) <= $this->maxOpen) {
                return;
            }
            if ($host->isBusy() || (string) $sessionId === $keep) {
                continue;
            }
            unset($this->hosts[$sessionId]);
            $host->release();
        }
    }
}

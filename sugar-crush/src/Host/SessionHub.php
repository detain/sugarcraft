<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host;

use SugarCraft\Crush\Context\CompactorConfig;
use SugarCraft\Crush\Session\EnhancedSessionStore;

/**
 * The open sessions of one workspace, each with its {@see SessionHost}
 * (roadmap O-2g, Appendix O §4.3–§4.5).
 *
 * WHAT IT OWNS. Which sessions are open in this process, the lock each one
 * holds, and how many may be open at once. Opening a session loads its
 * transcript through the workspace's {@see TranscriptStore} and takes the
 * same flock `SessionLock` a TUI takes (§4.8: the lease already exists), so a
 * session another sugarcrush has open is refused here exactly as the TUI's
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
                throw new \RuntimeException(sprintf(
                    'Session %s is open in another sugarcrush%s; only one process may write it.',
                    $sessionId,
                    $holder === null ? '' : " (pid {$holder})",
                ));
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
     */
    public function create(?string $sessionId = null, ?string $name = null, string $provider = '', string $model = ''): SessionHost
    {
        $sessionId ??= bin2hex(random_bytes(8));
        $store = $this->workspace->sessionStore;
        if ($store !== null && $store->getSession($sessionId) === null) {
            $store->createSession($sessionId, $provider, $model, name: $name, cwd: $this->workspace->root);
        }

        return $this->open($sessionId);
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
     * The workspace's sessions with their metadata, newest activity first
     * (`EnhancedSessionStore::listSessionsWithMeta()`), each marked with
     * whether this hub has it open. $cursor is the id of the last row of the
     * previous page; the page starts after it.
     *
     * @return array{sessions: list<array<string, mixed>>, nextCursor: ?string}
     */
    public function list(int $limit = 20, ?string $cursor = null): array
    {
        $store = $this->workspace->sessionStore;
        if (!$store instanceof EnhancedSessionStore || $limit < 1) {
            return ['sessions' => [], 'nextCursor' => null];
        }

        // One row beyond the page tells whether another page exists; a cursor
        // means reading past everything up to it.
        $rows = $store->listSessionsWithMeta(PHP_INT_MAX);
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

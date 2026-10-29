<?php

declare(strict_types=1);

namespace SugarCraft\Code\Protocol;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use SugarCraft\Code\Host\BackgroundEvents;
use SugarCraft\Code\Host\SessionEvent;
use SugarCraft\Code\Host\SessionHost;
use SugarCraft\Code\Host\SessionHub;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Server\ErrorLog;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Workspace\PrStatusCache;

/**
 * What every `candy-code.v1` method reaches: the workspace's
 * {@see SessionHub}, the server's {@see ServerConfig}, the clients connected
 * now, one {@see SessionFeed} per open session, and the server's own state —
 * its version, its uptime, whether it is draining.
 *
 * SESSIONS ARE DRIVEN THROUGH THE HUB (Appendix O §4.3). A method never builds
 * a {@see SessionHost} itself: {@see host()} opens one through the hub, which
 * holds the same flock lock a TUI takes, so a session open in a terminal is
 * refused here (`conflict` / `session_locked`) rather than written by two.
 *
 * WHAT EVERY CLIENT HEARS AT ONCE (roadmap O-6a). A session's own events
 * reach only the clients following it, but three things matter to every
 * client — the sidebar's status dots, the approvals drawer, the tab title —
 * so a feed offers each event it hears here ({@see heard()}): a question put
 * becomes a server-scope `permission.asked`, its settling `permission.settled`,
 * and a status change a `session.updated` carrying the session's summary.
 * A client watching one session learns of a question in another the moment
 * it is put, not at the next `server.tick`.
 *
 * MUTABLE ON PURPOSE: the live registry of one server's clients and feeds.
 */
final class ServerContext
{
    /** @var array<string, Client> */
    private array $clients = [];

    /** @var array<string, SessionFeed> */
    private array $feeds = [];

    private bool $draining = false;

    private ?BackgroundEvents $background = null;

    private bool $backgroundResolved = false;

    /** @var (\Closure(?float): void)|null */
    private ?\Closure $shutdown = null;

    private readonly float $startedAt;

    /** Whole-second stamp of the last loop advance (C-AGT-1.b), null until the first. */
    private ?int $loopTickAt = null;

    /**
     * @param \Closure(): float          $clock
     * @param (\Closure(string): void)|null $log the server's log sink; internal failures reach the client as codes only, the cause comes here (ISS-057)
     */
    private function __construct(
        private readonly SessionHub $hub,
        private readonly ServerConfig $config,
        private readonly string $version,
        private readonly LoopInterface $loop,
        private readonly \Closure $clock,
        private readonly ?\Closure $log = null,
    ) {
        $this->startedAt = ($this->clock)();

        // ISS-004: the host-side `/bg` (and `/fork`) spawn through this same
        // supervisor and name no mode of their own — bg.spawn carries the
        // admitted mode on every call, and this default makes the slash
        // command's path spawn in it too. A mode the launch would not admit
        // (constructible only around problems(), never through a real boot)
        // downgrades to `default`, whose asks the daemon's no-terminal
        // approver refuses: fail closed, never into the daemon's documented
        // bypass default. A local TUI builds no ServerContext at all, so its
        // supervisor keeps the pre-ISS-004 daemon default untouched.
        $admitted = $config->admitsPermissionMode($config->permissionMode)
            ? $config->permissionMode
            : PermissionMode::Default;
        $hub->workspace()->backgroundSupervisor?->setSpawnPermissionMode($admitted);
    }

    /**
     * @param (\Closure(): float)|null       $clock
     * @param (\Closure(string): void)|null  $log
     */
    public static function new(
        SessionHub $hub,
        ServerConfig $config,
        string $version = 'dev',
        ?LoopInterface $loop = null,
        ?\Closure $clock = null,
        ?\Closure $log = null,
    ): self {
        return new self($hub, $config, $version, $loop ?? Loop::get(), $clock ?? static fn (): float => \microtime(true), $log);
    }

    public function hub(): SessionHub
    {
        return $this->hub;
    }

    /**
     * One swallowed internal failure, rendered for the server log (ISS-057).
     * Callers that hide the cause from the client — the `-32099` arm of the
     * dispatcher — owe it a line here; without a sink the failure stays
     * exactly as silent as it was before.
     */
    public function logError(\Throwable $e, string $prefix = 'internal error'): void
    {
        if ($this->log === null) {
            return;
        }
        ($this->log)(ErrorLog::line($e, $prefix));
    }

    public function config(): ServerConfig
    {
        return $this->config;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function loop(): LoopInterface
    {
        return $this->loop;
    }

    /** @return \Closure(): float */
    public function clock(): \Closure
    {
        return $this->clock;
    }

    public function uptimeSeconds(): int
    {
        return (int) (($this->clock)() - $this->startedAt);
    }

    /** The workspace's session store when it keeps metadata, else null. */
    public function store(): ?EnhancedSessionStore
    {
        $store = $this->hub->workspace()->sessionStore;

        return $store instanceof EnhancedSessionStore ? $store : null;
    }

    /**
     * The workspace's background sessions as `bg.*` events broadcast to every
     * client (roadmap O-4b), built on first use; null when the workspace
     * supervises none. The {@see Dispatcher} boots it — re-adopting what an
     * earlier server left running — and polls it.
     */
    public function background(): ?BackgroundEvents
    {
        if (!$this->backgroundResolved) {
            $this->backgroundResolved = true;
            $workspace = $this->hub->workspace();
            if ($workspace->backgroundSupervisor !== null) {
                $this->background = BackgroundEvents::new(
                    $workspace->backgroundSupervisor,
                    $workspace->root,
                    function (string $type, array $data): void {
                        $this->broadcast(EventEnvelope::server($type, $data));
                    },
                    // AUT-1: `serve` is a cron HOST. The same row a TUI's pump
                    // eyes rides this poll too; the per-row file lock decides
                    // which process fires it, so two hosts on one home get one
                    // fire. Fired sessions are ordinary daemons, so their
                    // `bg.started` rows come from this poll's own status diff.
                    $workspace->service(\SugarCraft\Code\Background\ScheduleRegistry::class),
                    $workspace->agentManager,
                );
            }
        }

        return $this->background;
    }

    // ── lifecycle ──────────────────────────────────────────────────────

    /**
     * What `server.shutdown` (and a drain) calls: the serve loop's own stop.
     *
     * @param \Closure(?float): void $shutdown given the drain seconds the client asked for
     */
    public function onShutdown(\Closure $shutdown): void
    {
        $this->shutdown = $shutdown;
    }

    /** @return bool whether anything was asked to stop */
    public function shutdown(?float $drainSeconds = null): bool
    {
        if ($this->shutdown === null) {
            return false;
        }
        ($this->shutdown)($drainSeconds);

        return true;
    }

    /** From now on no new turn or session is admitted. */
    public function beginDraining(): void
    {
        $this->draining = true;
    }

    public function isDraining(): bool
    {
        return $this->draining;
    }

    // ── clients ────────────────────────────────────────────────────────

    public function addClient(Client $client): void
    {
        $this->clients[$client->id()] = $client;
    }

    public function removeClient(Client $client): void
    {
        unset($this->clients[$client->id()]);
        foreach ($this->feeds as $feed) {
            $feed->unsubscribe($client);
        }
    }

    /** @return list<Client> */
    public function clients(): array
    {
        return \array_values($this->clients);
    }

    /**
     * A server-scope event to every client that said hello. Session lifecycle
     * events must arrive; ticks and notices may be shed under backpressure.
     */
    public function broadcast(EventEnvelope $event, bool $mustArrive = true): void
    {
        foreach ($this->clients as $client) {
            if (!$client->isInitialized()) {
                continue;
            }
            if ($mustArrive) {
                $client->send(JsonRpc::encode($event->notification()));
            } else {
                $client->outbox()->pushEphemeral($event->notification());
            }
        }
    }

    // ── sessions ───────────────────────────────────────────────────────

    /**
     * The open host of $sessionId, opening it (and taking its lock) when the
     * hub does not hold it yet.
     *
     * @throws RpcError not_found for a session the store has never seen;
     *                  conflict / session_locked when another process has it open
     */
    public function host(string $sessionId): SessionHost
    {
        $open = $this->hub->get($sessionId);
        if ($open !== null) {
            return $this->hub->open($sessionId);
        }

        $store = $this->hub->workspace()->sessionStore;
        if ($store !== null && $store->session($sessionId) === null) {
            throw RpcError::notFound(\sprintf('no session %s', $sessionId), 'session_not_found');
        }

        try {
            return $this->hub->open($sessionId);
        } catch (\RuntimeException $e) {
            throw RpcError::of(ErrorCode::Conflict, $e->getMessage(), 'session_locked');
        }
    }

    /** The feed of $host, attached on first use and re-attached if the hub replaced the host. */
    public function feedFor(SessionHost $host): SessionFeed
    {
        $sessionId = $host->sessionId();
        $feed = $this->feeds[$sessionId] ?? null;
        if ($feed !== null && $feed->host() === $host) {
            return $feed;
        }
        $feed?->detach();
        if ($feed === null) {
            // A session the server opens runs in the server's mode
            // (`default` unless the operator chose otherwise), whatever the
            // workspace gate says — until a client sets its own.
            $host->setPermissionMode($this->config->permissionMode);
        }

        // C-WEB-1.a: a served `/model` or `/rename` changes what the session
        // summary reports without a status transition, so the feed's status
        // lane never announces it. Bind the host's summary-change hook to the
        // same server-scope broadcast a rename already rides from
        // `session.rename` — bound HERE, wherever the feed attaches, so a host
        // the hub re-opened (a fresh object for a session id the feed held) is
        // never left silent again.
        $host->onSummaryChanged(function () use ($sessionId): void {
            $this->broadcast(EventEnvelope::server(EventType::SESSION_UPDATED, $this->summaryOf($sessionId)));
        });

        return $this->feeds[$sessionId] = SessionFeed::attach(
            $host,
            $this->loop,
            $this->config->askTimeoutSeconds,
            $this->clock,
            fn (SessionEvent $event) => $this->heard($sessionId, $event),
        );
    }

    /**
     * A session as `session.list` and the `session.*` events describe it.
     *
     * The spend pair is read from ONE source per state (SES-1/SES-2): an
     * OPEN session reports its live in-process tracker, a closed one falls
     * back to the store's cached `cost_usd`/`cost_unpriced` — the same
     * figures the ledger folds onto the row inside its own write transaction,
     * so no query re-SUMs `usage_records` per list page. `snippet` rides only
     * on a row the hub read through the ranked body search; every other
     * summary carries null, exactly like SessionRow's own law.
     *
     * @param array<string, mixed> $row a `sessions` row
     * @return array<string, mixed>
     */
    public function summary(array $row): array
    {
        $id = (string) ($row['id'] ?? '');
        $host = $this->hub->get($id);
        // C-WEB-1.a: a served `/model` rewires the live session and never
        // touches the store row (W7a-2 keeps the provider axis unpersisted on
        // both surfaces), so the OPEN host's override names what is actually
        // answering; a closed host has no override, and the row is the whole
        // truth again — next launch resolves the saved choice through the
        // launch's own layers, exactly as the terminal's does.
        $override = $host?->modelOverride();

        return [
            'id' => $id,
            'name' => $row['name'] ?? null,
            'provider' => ($override['provider'] ?? '') !== '' ? $override['provider'] : ($row['provider'] ?? null),
            'model' => ($override['model'] ?? '') !== '' ? $override['model'] : ($row['model'] ?? null),
            'kind' => $row['kind'] ?? null,
            'parentId' => $row['parent_id'] ?? null,
            'createdAt' => $row['created_at'] ?? null,
            'updatedAt' => $row['last_activity'] ?? ($row['updated_at'] ?? null),
            'turns' => isset($row['turns']) ? (int) $row['turns'] : null,
            'preview' => $row['last_preview'] ?? null,
            'snippet' => $row['bodySnippet'] ?? null,
            // C-WEB-4.f: the branch-at-session-start from the row; the row is
            // the whole truth here — nothing on this path calls git.
            'branch' => $row['git_branch'] ?? null,
            // C-AGT-6.e (R13): the PR/CI read-out for the web's chips. This
            // call only registers the watch and answers what the cache's last
            // off-summary refresh stored (null until then) — the gh run never
            // happens on this path, per the step's non-blocking law.
            'pr' => PrStatusCache::observe(
                $row['git_branch'] ?? null,
                $this->hub->workspace()->root,
                $this->loop,
            ),
            'open' => $host !== null,
            'status' => $host === null ? 'closed' : $this->feedFor($host)->status(),
            'permissionMode' => $host?->permissionMode()?->value,
            'spentUsd' => $host !== null
                ? $host->spentUsd()
                : (isset($row['cost_usd']) && \is_numeric($row['cost_usd']) ? (float) $row['cost_usd'] : null),
            'spendUnpriced' => (int) ($row['cost_unpriced'] ?? 0) !== 0,
            // C-SES-7.e: the archive tray state lives on the row; the
            // incognito state lives on the open host (a closed one left no
            // trace — that is the point).
            'archived' => ($row['archived_at'] ?? null) !== null,
            'ephemeral' => $host?->isEphemeral() === true,
        ];
    }

    /**
     * The summary of $sessionId, read from the store (a bare row when it keeps none).
     *
     * @return array<string, mixed>
     */
    public function summaryOf(string $sessionId): array
    {
        $row = $this->hub->workspace()->sessionStore?->session($sessionId) ?? ['id' => $sessionId];
        if (($row['name'] ?? null) === null) {
            // C-SES-7.e: an incognito session has no store row at all, and a
            // name given to `session.create {ephemeral:true}` rides the open
            // host instead; for every other session this answers null and
            // leaves the row as it was.
            $row['name'] = $this->hub->get($sessionId)?->ephemeralName();
        }

        return $this->summary($row);
    }

    /**
     * One event a session's feed heard, made server-scope when every client
     * should know of it — whether or not it follows the session.
     */
    private function heard(string $sessionId, SessionEvent $event): void
    {
        switch ($event->type) {
            case SessionEvent::PERMISSION_REQUESTED:
                $this->broadcast(EventEnvelope::server(EventType::PERMISSION_ASKED, ['sessionId' => $sessionId, ...$event->data]));
                break;

            case SessionEvent::PERMISSION_RESOLVED:
                $this->broadcast(EventEnvelope::server(EventType::PERMISSION_SETTLED, \array_filter([
                    'sessionId' => $sessionId,
                    'askId' => $event->data['askId'] ?? null,
                    'reply' => $event->data['reply'] ?? null,
                    'cancelled' => $event->data['cancelled'] ?? null,
                ], static fn (mixed $value): bool => $value !== null)));
                break;

            case SessionEvent::SESSION_STATUS:
                $this->broadcast(EventEnvelope::server(EventType::SESSION_UPDATED, $this->summaryOf($sessionId)));
                break;
        }
    }

    /** The feed of an open session, without opening anything. */
    public function feed(string $sessionId): ?SessionFeed
    {
        $host = $this->hub->get($sessionId);

        return $host === null ? null : $this->feedFor($host);
    }

    /**
     * The feeds $client follows, without opening or attaching anything.
     *
     * @return list<SessionFeed>
     */
    public function followedBy(Client $client): array
    {
        $followed = [];
        foreach ($this->feeds as $feed) {
            if ($feed->isSubscribed($client)) {
                $followed[] = $feed;
            }
        }

        return $followed;
    }

    /** Forget $sessionId's feed (its host was closed). */
    public function dropFeed(string $sessionId): void
    {
        ($this->feeds[$sessionId] ?? null)?->detach();
        unset($this->feeds[$sessionId]);
        // C-WEB-1.a: the summary hook lives exactly as long as the feed that
        // bound it — a host released from following announces nothing, the
        // same silence its missing status lane keeps.
        $this->hub->get($sessionId)?->onSummaryChanged(null);
    }

    /** Detach every feed (the server is stopping). */
    public function dropFeeds(): void
    {
        foreach (\array_keys($this->feeds) as $sessionId) {
            $this->dropFeed((string) $sessionId);
        }
    }

    /** How many open sessions have a turn in flight. */
    public function turnsRunning(): int
    {
        $running = 0;
        foreach ($this->hub->openSessionIds() as $sessionId) {
            if ($this->hub->get($sessionId)?->isBusy() === true) {
                $running++;
            }
        }

        return $running;
    }

    /**
     * Fold every running turn's live events (the host's tick). Also notices a
     * host the hub evicted, and drops its feed.
     *
     * C-AGT-1.b adds the served `/loop` advance: every OPEN host gets its
     * {@see \SugarCraft\Code\Host\SessionHost::tick()} — not only busy ones,
     * because an idle session is exactly when a due loop wants to run. The
     * whole sweep rides this pump's own clock at most once per wall-second
     * ($loopTickAt), so a fast caller cannot spin loops faster than their
     * cadence; no per-row timer is ever minted (E646). The TUI pump is
     * unchanged — this path exists only because a served session has no Chat
     * instance to run {@see \SugarCraft\Code\Chat::pumpSchedules()}.
     */
    public function pump(): void
    {
        $now = (int) (($this->clock)());
        $fireLoops = $now !== $this->loopTickAt;
        if ($fireLoops) {
            $this->loopTickAt = $now;
        }
        foreach ($this->hub->openSessionIds() as $sessionId) {
            $host = $this->hub->get($sessionId);
            if ($host === null) {
                continue;
            }
            if ($fireLoops) {
                $host->tick($now);
            }
            if ($host->isBusy()) {
                $this->feedFor($host);
                $host->pump();
            }
        }
        foreach (\array_keys($this->feeds) as $sessionId) {
            if (!$this->hub->isOpen((string) $sessionId)) {
                $this->dropFeed((string) $sessionId);
            }
        }
    }
}

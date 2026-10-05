<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Protocol;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use SugarCraft\Crush\Host\BackgroundEvents;
use SugarCraft\Crush\Host\SessionEvent;
use SugarCraft\Crush\Host\SessionHost;
use SugarCraft\Crush\Host\SessionHub;
use SugarCraft\Crush\Server\ServerConfig;
use SugarCraft\Crush\Session\EnhancedSessionStore;

/**
 * What every `sugarcrush.v1` method reaches: the workspace's
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

    /** @param \Closure(): float $clock */
    private function __construct(
        private readonly SessionHub $hub,
        private readonly ServerConfig $config,
        private readonly string $version,
        private readonly LoopInterface $loop,
        private readonly \Closure $clock,
    ) {
        $this->startedAt = ($this->clock)();
    }

    /** @param (\Closure(): float)|null $clock */
    public static function new(
        SessionHub $hub,
        ServerConfig $config,
        string $version = 'dev',
        ?LoopInterface $loop = null,
        ?\Closure $clock = null,
    ): self {
        return new self($hub, $config, $version, $loop ?? Loop::get(), $clock ?? static fn (): float => \microtime(true));
    }

    public function hub(): SessionHub
    {
        return $this->hub;
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
        if ($store !== null && $store->getSession($sessionId) === null) {
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
     * @param array<string, mixed> $row a `sessions` row
     * @return array<string, mixed>
     */
    public function summary(array $row): array
    {
        $id = (string) ($row['id'] ?? '');
        $host = $this->hub->get($id);

        return [
            'id' => $id,
            'name' => $row['name'] ?? null,
            'provider' => $row['provider'] ?? null,
            'model' => $row['model'] ?? null,
            'kind' => $row['kind'] ?? null,
            'parentId' => $row['parent_id'] ?? null,
            'createdAt' => $row['created_at'] ?? null,
            'updatedAt' => $row['last_activity'] ?? ($row['updated_at'] ?? null),
            'turns' => isset($row['turns']) ? (int) $row['turns'] : null,
            'preview' => $row['last_preview'] ?? null,
            'open' => $host !== null,
            'status' => $host === null ? 'closed' : $this->feedFor($host)->status(),
            'permissionMode' => $host?->permissionMode()?->value,
            'spentUsd' => $host?->spentUsd(),
        ];
    }

    /**
     * The summary of $sessionId, read from the store (a bare row when it keeps none).
     *
     * @return array<string, mixed>
     */
    public function summaryOf(string $sessionId): array
    {
        $row = $this->hub->workspace()->sessionStore?->getSession($sessionId) ?? ['id' => $sessionId];

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
     */
    public function pump(): void
    {
        foreach ($this->hub->openSessionIds() as $sessionId) {
            $host = $this->hub->get($sessionId);
            if ($host !== null && $host->isBusy()) {
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

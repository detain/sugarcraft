<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Protocol;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
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
 * MUTABLE ON PURPOSE: the live registry of one server's clients and feeds.
 */
final class ServerContext
{
    /** @var array<string, Client> */
    private array $clients = [];

    /** @var array<string, SessionFeed> */
    private array $feeds = [];

    private bool $draining = false;

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

        return $this->feeds[$sessionId] = SessionFeed::attach($host, $this->loop, $this->config->askTimeoutSeconds, $this->clock);
    }

    /** The feed of an open session, without opening anything. */
    public function feed(string $sessionId): ?SessionFeed
    {
        $host = $this->hub->get($sessionId);

        return $host === null ? null : $this->feedFor($host);
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

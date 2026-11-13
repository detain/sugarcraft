<?php

declare(strict_types=1);

namespace SugarCraft\Code\Server\Workspace;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\PromiseInterface;
use SugarCraft\Code\Host\SessionEvent;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Protocol\Dispatcher;
use SugarCraft\Code\Protocol\ErrorCode;
use SugarCraft\Code\Protocol\EventEnvelope;
use SugarCraft\Code\Protocol\EventType;
use SugarCraft\Code\Protocol\JsonRpc;
use SugarCraft\Code\Protocol\Methods\FsMethods;
use SugarCraft\Code\Protocol\RpcError;
use SugarCraft\Code\Server\ServerConfig;
use SugarCraft\Code\Server\Ws\Connection;
use SugarCraft\Code\Server\Ws\MessageHandler;
use SugarCraft\Code\Server\Ws\Outbox;
use SugarCraft\Code\Support\Directories\DirectoryBrowserException;

/**
 * Multi-root `serve` (roadmap O-7, Appendix O §4.1 "Phase 7"): the
 * {@see MessageHandler} in front of the server's own {@see Dispatcher} that
 * sends the requests for OTHER project roots to a workspace-host child per
 * root ({@see WorkspaceHostClient}, {@see WorkspaceHostProcess}).
 *
 * WHAT GOES WHERE. Everything about the server's own root — the one it was
 * started on — goes to the inner dispatcher untouched. A request goes to a
 * workspace host when its params name another `root`, or name a `sessionId`
 * the gateway learnt belongs to one (from a `session.create`, `session.list`
 * or `session.fork` it relayed, or an event it heard). The `root` param is
 * taken off before either sees the request: it is the gateway's.
 *
 * Three methods are the gateway's own, answered here:
 *
 *  - `workspace.list` → `{items: [{root, primary, running, pid}]}`;
 *  - `workspace.open {root, browse?}` → starts that root's host (or finds it
 *    running); with `browse: true` (the web UI's directory picker) only while
 *    `--allow-dir-browse` is on and inside the browse root;
 *  - `workspace.close {root, force?}` → stops it; refused while one of its
 *    turns runs, unless `force`.
 *
 * ONE UPSTREAM CONNECTION PER HOST. The gateway is the host's only client, so
 * it fans the host's events out: a session event to the clients that
 * subscribed to that session through it, a server-scope one to every client
 * that used the root, each with `root` added to the envelope. A second client
 * subscribing to the same session re-subscribes the gateway upstream, which
 * replays from that client's cursor; the clients already following it are
 * shielded from the replay by a per-client, per-session cursor (a durable
 * event at or below it is not sent twice). The last subscriber leaving — by
 * `session.unsubscribe` or by disconnecting — unsubscribes upstream.
 *
 * A HOST THAT DIES (crash, kill, lost socket) is forgotten; each of its
 * followed sessions' subscribers is sent `server.overflow {sessionId, action:
 * "resubscribe"}`, and the next request for that root starts a fresh host. A
 * host nobody follows and that runs no turn is RECYCLED after
 * {@see IDLE_RECYCLE_SECONDS} — the bound on a long-running PHP process's
 * memory growth Appendix O §9.4 asks for.
 *
 * `sessionRoots` IS BOUNDED, NOT PRUNED ON CRASH. A session id with no `root`
 * param is routed only through that map, and crash recovery depends on it:
 * after {@see lost()} the client gets `server.overflow {action:"resubscribe"}`
 * and resubscribes naming just the sessionId, so the surviving row is what
 * lets the gateway start a fresh host for the right root. Dropping rows in
 * lost() would break that, so they stay; growth is capped instead at
 * {@see MAX_SESSION_ROOTS} with least-recently-used eviction (PHP arrays keep
 * insertion order; every use re-inserts the row at the young end). A row is
 * NEVER evicted while its session has a live subscriber or its root has a
 * running host, so the cap can only forget idle sessions of dead hosts - the
 * client of such a session must name its `root` again, as any fresh client does.
 *
 * MUTABLE ON PURPOSE: the live routing state of one server.
 */
final class Gateway implements MessageHandler
{
    /** The gateway's own methods. */
    public const METHODS = ['workspace.list', 'workspace.open', 'workspace.close'];

    /** How many workspace hosts may run at once. */
    public const MAX_HOSTS = 8;

    /** An idle host (no subscriber, no turn) is stopped after this long. */
    public const IDLE_RECYCLE_SECONDS = 900.0;

    /** How often idle hosts are looked for. */
    public const RECYCLE_CHECK_SECONDS = 60.0;

    /** Most session-to-root routing rows kept; idle least-recently-used rows go first. */
    public const MAX_SESSION_ROOTS = 4096;

    /** The methods whose answer names sessions the gateway should route. */
    private const LEARNS_SESSIONS = ['session.create', 'session.fork', 'session.list'];

    /** @var array<string, WorkspaceHostClient> root => its running host */
    private array $hosts = [];

    /** @var array<string, PromiseInterface<WorkspaceHostClient>> root => its host starting */
    private array $starting = [];

    /** @var array<string, string> sessionId => the root whose host has it */
    private array $sessionRoots = [];

    private int $maxSessionRoots = self::MAX_SESSION_ROOTS;

    /** @var array<string, array<string, Connection>> sessionId => connection id => subscriber */
    private array $subscribers = [];

    /** @var array<string, array<string, int>> connection id => sessionId => last durable seq sent */
    private array $cursors = [];

    /** @var array<string, array<string, Connection>> root => connection id => a client that used it */
    private array $rootClients = [];

    /** @var array<string, true> connection ids that said `server.hello` */
    private array $greeted = [];

    /** @var array<string, int> root => calls still waiting on its host */
    private array $inFlight = [];

    private ?TimerInterface $recycleTimer = null;

    private bool $stopped = false;

    /**
     * @param array<string, string> $childEnv overrides on the environment a
     *        workspace host inherits
     */
    private function __construct(
        private readonly Dispatcher $primary,
        private readonly ServerConfig $config,
        private readonly string $primaryRoot,
        private readonly string $runDir,
        private readonly string $version,
        private readonly LoopInterface $loop,
        private readonly array $childEnv,
    ) {
    }

    /**
     * A gateway over $primary, the server's own dispatcher for
     * `$config->root`. Workspace hosts keep their listener and log in
     * `<stateDir>/workspaces` unless $runDir says otherwise.
     *
     * @param array<string, string> $childEnv
     */
    public static function new(
        Dispatcher $primary,
        ServerConfig $config,
        string $version,
        ?LoopInterface $loop = null,
        ?string $runDir = null,
        array $childEnv = [],
    ): self {
        $root = $config->root ?? '';
        $canonical = $root === '' ? '' : (\realpath($root) ?: $root);

        return new self(
            $primary,
            $config,
            $canonical,
            $runDir ?? \rtrim($config->stateDir, '/') . '/workspaces',
            $version,
            $loop ?? Loop::get(),
            $childEnv,
        );
    }

    public function primary(): Dispatcher
    {
        return $this->primary;
    }

    /** Start looking for idle hosts to recycle. */
    public function start(): void
    {
        if ($this->stopped) {
            return;
        }
        $this->recycleTimer ??= $this->loop->addPeriodicTimer(self::RECYCLE_CHECK_SECONDS, fn () => $this->recycleIdle());
    }

    /** Stop every workspace host and the recycling; idempotent. */
    public function stop(string $reason = 'server shutting down'): void
    {
        if ($this->recycleTimer !== null) {
            $this->loop->cancelTimer($this->recycleTimer);
            $this->recycleTimer = null;
        }
        $this->stopped = true;
        foreach ($this->hosts as $host) {
            $host->stop($reason);
        }
        $this->hosts = [];
    }

    /**
     * The roots with a running workspace host.
     *
     * @return list<string>
     */
    public function hostRoots(): array
    {
        return \array_keys($this->hosts);
    }

    public function host(string $root): ?WorkspaceHostClient
    {
        return $this->hosts[$root] ?? null;
    }

    /** The root whose host has $sessionId, when it is not the server's own. */
    public function rootOf(string $sessionId): ?string
    {
        return $this->touchSession($sessionId);
    }

    // ── MessageHandler ─────────────────────────────────────────────────

    public function onOpen(Connection $connection): void
    {
        $this->primary->onOpen($connection);
    }

    public function onMessage(Connection $connection, string $payload): void
    {
        $message = \json_decode($payload, false, JsonRpc::MAX_DEPTH);
        if (!$message instanceof \stdClass || !\is_string($message->method ?? null) || !\property_exists($message, 'id')) {
            $this->primary->onMessage($connection, $payload);

            return;
        }
        $method = $message->method;
        $params = ($message->params ?? null) instanceof \stdClass ? $message->params : new \stdClass();
        $id = \is_string($message->id) || \is_int($message->id) ? $message->id : null;

        if ($method === 'server.hello') {
            $this->greeted[$connection->id()] = true;
            $this->primary->onMessage($connection, $payload);

            return;
        }

        if (\in_array($method, self::METHODS, true)) {
            if (!isset($this->greeted[$connection->id()])) {
                $this->send($connection, JsonRpc::error($id, RpcError::of(ErrorCode::NotInitialized, 'send server.hello first')));

                return;
            }
            if (!$this->admit($connection, $id, $method, $params, null)) {
                return;
            }
            $this->own($connection, $id, $method, $params);

            return;
        }

        $root = null;
        try {
            $root = $this->routeOf($params);
        } catch (RpcError $e) {
            $this->send($connection, JsonRpc::error($id, $e));

            return;
        }
        if ($root === null) {
            if (\property_exists($params, 'root')) {
                unset($params->root);
                $message->params = $params;
                $payload = (string) \json_encode($message, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            }
            $this->primary->onMessage($connection, $payload);

            return;
        }
        if (!isset($this->greeted[$connection->id()])) {
            $this->send($connection, JsonRpc::error($id, RpcError::of(ErrorCode::NotInitialized, 'send server.hello first')));

            return;
        }

        if (!$this->admit($connection, $id, $method, $params, $root)) {
            return;
        }

        unset($params->root);
        $this->forward($connection, $id, $root, $method, $params);
    }

    /**
     * The scope law at the gateway's own door. The Dispatcher enforces scope,
     * the viewer-session pin and the method roster for what it answers, but a
     * request the gateway answers or relays never reaches it - and a
     * workspace host trusts its parent as the owner. So everything the gateway
     * intercepts is judged HERE, with the same rules, before it can start a
     * host or reach one. An owner-class socket (no scope list on its
     * connection) passes untouched; a viewer grant:
     *
     *  - needs the method's scope (an unknown method is refused, never relayed
     *    to a host that would answer for the owner);
     *  - never touches `workspace.*` (it lists roots and pids, and opens or
     *    stops hosts);
     *  - reaches another root only through a host that is already running -
     *    it cannot cause one to start;
     *  - when pinned to sessions, may only name a pinned session.
     *
     * @return bool true when the request may proceed (a refusal was sent otherwise)
     */
    private function admit(Connection $connection, string|int|null $id, string $method, \stdClass $params, ?string $root): bool
    {
        $granted = $connection->scopes();
        $viewer = $connection->principal() === 'viewer';
        if ($granted === null && !$viewer) {
            return true;
        }
        try {
            $spec = $this->primary->registry()->get($method)
                ?? throw RpcError::of(ErrorCode::MethodNotFound, \sprintf('method not found: %s', \substr($method, 0, 64)));
            if (!\in_array($spec->scope->value, $granted ?? [], true)) {
                throw RpcError::of(ErrorCode::Forbidden, \sprintf('this needs the %s scope', $spec->scope->value));
            }
            if (\in_array($method, self::METHODS, true)) {
                throw RpcError::of(ErrorCode::Forbidden, 'this needs the admin scope', 'owner_only');
            }
            if ($viewer && ($pins = $connection->viewerSessions()) !== null) {
                $sessionId = $params->sessionId ?? null;
                if (!\is_string($sessionId) || !\in_array($sessionId, $pins, true)) {
                    throw RpcError::of(ErrorCode::Forbidden, Lang::t('cli.serve.tokens.session_denied'), 'viewer_session_denied');
                }
            }
            if ($viewer && $root !== null && !isset($this->hosts[$root])) {
                throw RpcError::of(ErrorCode::Forbidden, 'a viewer may only reach a workspace that is already running', 'owner_only');
            }
        } catch (RpcError $e) {
            $this->send($connection, JsonRpc::error($id, $e));

            return false;
        }

        return true;
    }

    public function onClose(Connection $connection, int $code): void
    {
        $this->primary->onClose($connection, $code);
        $connectionId = $connection->id();
        unset($this->greeted[$connectionId], $this->cursors[$connectionId]);
        foreach ($this->rootClients as $root => $clients) {
            unset($this->rootClients[$root][$connectionId]);
        }
        foreach (\array_keys($this->subscribers) as $sessionId) {
            if (isset($this->subscribers[$sessionId][$connectionId])) {
                $this->dropSubscriber((string) $sessionId, $connectionId);
            }
        }
    }

    // ── routing ─────────────────────────────────────────────────────────

    /**
     * The root a request goes to, or null for the server's own dispatcher.
     *
     * @throws RpcError when `root` is not a directory
     */
    private function routeOf(\stdClass $params): ?string
    {
        if (\property_exists($params, 'root') && $params->root !== null) {
            $root = $this->canonicalRoot($params->root);

            return $root === $this->primaryRoot ? null : $root;
        }
        $sessionId = $params->sessionId ?? null;

        return \is_string($sessionId) ? $this->touchSession($sessionId) : null;
    }

    /** @throws RpcError */
    private function canonicalRoot(mixed $root): string
    {
        if (!\is_string($root) || \trim($root) === '' || \strlen($root) > 4096) {
            throw RpcError::invalidParams('root must be a directory path');
        }
        $real = \realpath($root);
        if ($real === false || !\is_dir($real)) {
            throw RpcError::notFound(\sprintf('%s is not a directory', $root), 'root_not_found');
        }

        return $real;
    }

    /**
     * A root picked through `fs.listDirs` (`workspace.open {root, browse:
     * true}`): only while directory browsing is on, and only inside the
     * browse root — the picker's own confinement, enforced here too rather
     * than trusted to the client.
     *
     * @throws RpcError
     */
    private function browsedRoot(mixed $root): string
    {
        if (!\is_string($root) || \trim($root) === '' || \strlen($root) > 4096) {
            throw RpcError::invalidParams('root must be a directory path');
        }
        try {
            return FsMethods::browser($this->config)->resolve($root);
        } catch (DirectoryBrowserException $e) {
            throw FsMethods::refusal($e);
        }
    }

    /**
     * Relay one request to $root's host — starting it first when it is not
     * running — and its answer back under the client's own id.
     */
    private function forward(Connection $connection, string|int|null $id, string $root, string $method, \stdClass $params): void
    {
        $this->rootClients[$root][$connection->id()] = $connection;
        $sessionId = \is_string($params->sessionId ?? null) ? $params->sessionId : null;

        // The last subscriber is the only one whose leaving reaches upstream.
        if ($method === 'session.unsubscribe' && $sessionId !== null) {
            $others = \array_diff_key($this->subscribers[$sessionId] ?? [], [$connection->id() => true]);
            $was = isset($this->subscribers[$sessionId][$connection->id()]);
            unset($this->subscribers[$sessionId][$connection->id()], $this->cursors[$connection->id()][$sessionId]);
            if ($others !== []) {
                $this->send($connection, JsonRpc::result($id, ['unsubscribed' => $was]));

                return;
            }
            unset($this->subscribers[$sessionId]);
        }

        $this->inFlight[$root] = ($this->inFlight[$root] ?? 0) + 1;
        $this->hostFor($root)->then(
            function (WorkspaceHostClient $host) use ($connection, $id, $root, $method, $params, $sessionId): PromiseInterface {
                $host->touch();

                return $host->rpc()->callPreserving($method, $params)->then(
                    function (mixed $result) use ($connection, $id, $root, $method, $params, $sessionId): void {
                        $this->learn($root, $method, $result);
                        if ($method === 'session.subscribe' && $sessionId !== null) {
                            $this->subscribers[$sessionId][$connection->id()] = $connection;
                            $this->cursors[$connection->id()][$sessionId] = \is_int($params->afterSeq ?? null)
                                ? $params->afterSeq
                                : (int) ($result->throughSeq ?? 0);
                        }
                        if ($method === 'session.delete' && $sessionId !== null) {
                            unset($this->sessionRoots[$sessionId], $this->subscribers[$sessionId]);
                        }
                        if ($result instanceof \stdClass && $method !== 'session.list') {
                            $result->root ??= $root;
                        }
                        if ($connection->isOpen()) {
                            $this->send($connection, (string) \json_encode(
                                ['jsonrpc' => JsonRpc::VERSION, 'id' => $id, 'result' => $result],
                                \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE,
                            ));
                        }
                    },
                );
            },
        )->then(null, function (\Throwable $e) use ($connection, $id, $root): void {
            if ($connection->isOpen()) {
                $this->send($connection, JsonRpc::error($id, $e instanceof RpcError ? $e : RpcError::of(
                    ErrorCode::Internal,
                    \sprintf('the workspace host for %s failed: %s', $root, \substr($e->getMessage(), 0, 300)),
                    'workspace_failed',
                )));
            }
        })->finally(function () use ($root): void {
            $this->inFlight[$root] = \max(0, ($this->inFlight[$root] ?? 1) - 1);
        });
    }

    /** The root of $sessionId, marking the row most recently used. */
    private function touchSession(string $sessionId): ?string
    {
        $root = $this->sessionRoots[$sessionId] ?? null;
        if ($root !== null) {
            unset($this->sessionRoots[$sessionId]);
            $this->sessionRoots[$sessionId] = $root;
        }

        return $root;
    }

    /** Record $sessionId as $root's (most recently used), then enforce the cap. */
    private function bindSession(string $sessionId, string $root): void
    {
        unset($this->sessionRoots[$sessionId]);
        $this->sessionRoots[$sessionId] = $root;
        if (\count($this->sessionRoots) <= $this->maxSessionRoots) {
            return;
        }
        foreach ($this->sessionRoots as $id => $rowRoot) {
            if (\count($this->sessionRoots) <= $this->maxSessionRoots) {
                break;
            }
            if ((string) $id === $sessionId || ($this->subscribers[$id] ?? []) !== [] || isset($this->hosts[$rowRoot])) {
                continue;
            }
            unset($this->sessionRoots[$id]);
        }
    }

    /** Note the sessions an answer names as $root's. */
    private function learn(string $root, string $method, mixed $result): void
    {
        if (!\in_array($method, self::LEARNS_SESSIONS, true) || !$result instanceof \stdClass) {
            return;
        }
        if (\is_string($result->id ?? null)) {
            $this->bindSession($result->id, $root);
        }
        foreach (\is_array($result->items ?? null) ? $result->items : [] as $item) {
            if ($item instanceof \stdClass && \is_string($item->id ?? null)) {
                $this->bindSession($item->id, $root);
                $item->root = $root;
            }
        }
    }

    // ── the gateway's own methods ──────────────────────────────────────

    private function own(Connection $connection, string|int|null $id, string $method, \stdClass $params): void
    {
        try {
            if ($method === 'workspace.list') {
                $this->send($connection, JsonRpc::result($id, ['items' => $this->workspaceList()]));

                return;
            }

            $root = $method === 'workspace.open' && ($params->browse ?? false) === true
                ? $this->browsedRoot($params->root ?? null)
                : $this->canonicalRoot($params->root ?? null);
            if ($method === 'workspace.open') {
                if ($root === $this->primaryRoot) {
                    $this->send($connection, JsonRpc::result($id, ['root' => $root, 'primary' => true, 'running' => true, 'pid' => \getmypid()]));

                    return;
                }
                $this->rootClients[$root][$connection->id()] = $connection;
                $this->hostFor($root)->then(
                    function (WorkspaceHostClient $host) use ($connection, $id, $root): void {
                        $this->send($connection, JsonRpc::result($id, ['root' => $root, 'primary' => false, 'running' => true, 'pid' => $host->pid()]));
                    },
                    function (\Throwable $e) use ($connection, $id): void {
                        $this->send($connection, JsonRpc::error($id, $e instanceof RpcError ? $e : RpcError::of(ErrorCode::Internal, \substr($e->getMessage(), 0, 300), 'workspace_failed')));
                    },
                );

                return;
            }

            // workspace.close
            if ($root === $this->primaryRoot) {
                throw RpcError::of(ErrorCode::Forbidden, 'the server\'s own root closes with the server', 'primary_root');
            }
            $host = $this->hosts[$root] ?? null;
            if ($host === null) {
                $this->send($connection, JsonRpc::result($id, ['closed' => false]));

                return;
            }
            if (($params->force ?? false) === true) {
                $this->retire($root, 'closed by a client');
                $this->send($connection, JsonRpc::result($id, ['closed' => true]));

                return;
            }
            $host->rpc()->call('server.health')->then(
                function (array $health) use ($connection, $id, $root): void {
                    if ((int) ($health['turnsRunning'] ?? 0) > 0) {
                        $this->send($connection, JsonRpc::error($id, RpcError::of(ErrorCode::Busy, 'a turn is running in that workspace; cancel it or close with force', 'turn_running')));

                        return;
                    }
                    $this->retire($root, 'closed by a client');
                    $this->send($connection, JsonRpc::result($id, ['closed' => true]));
                },
                static function (\Throwable $e) use ($connection, $id): void {
                    $this->send($connection, JsonRpc::error($id, RpcError::of(ErrorCode::Internal, \substr($e->getMessage(), 0, 300), 'workspace_failed')));
                },
            );
        } catch (RpcError $e) {
            $this->send($connection, JsonRpc::error($id, $e));
        }
    }

    /** @return list<array<string, mixed>> */
    private function workspaceList(): array
    {
        $items = [['root' => $this->primaryRoot, 'primary' => true, 'running' => true, 'pid' => \getmypid()]];
        foreach ($this->hosts as $root => $host) {
            $items[] = ['root' => $root, 'primary' => false, 'running' => $host->isRunning(), 'pid' => $host->pid()];
        }

        return $items;
    }

    // ── hosts ───────────────────────────────────────────────────────────

    /** @return PromiseInterface<WorkspaceHostClient> */
    private function hostFor(string $root): PromiseInterface
    {
        if (isset($this->hosts[$root])) {
            return \React\Promise\resolve($this->hosts[$root]);
        }
        if (isset($this->starting[$root])) {
            return $this->starting[$root];
        }
        if ($this->stopped) {
            return \React\Promise\reject(RpcError::of(ErrorCode::Busy, 'the server is shutting down', 'draining'));
        }
        if (\count($this->hosts) + \count($this->starting) >= self::MAX_HOSTS) {
            return \React\Promise\reject(RpcError::of(ErrorCode::Busy, \sprintf('%d workspaces are already open; close one first', self::MAX_HOSTS), 'too_many_workspaces'));
        }

        $promise = WorkspaceHostClient::start($root, $this->config, $this->runDir, $this->version, $this->loop, $this->childEnv)->then(
            function (WorkspaceHostClient $host) use ($root): WorkspaceHostClient {
                unset($this->starting[$root]);
                if ($this->stopped) {
                    $host->stop('the server is shutting down');

                    throw RpcError::of(ErrorCode::Busy, 'the server is shutting down', 'draining');
                }
                $this->hosts[$root] = $host;
                $host->rpc()->onEvent(function (array $envelope, string $payload) use ($root): void {
                    $this->relay($root, $envelope, $payload);
                });
                $host->onExit(function () use ($root, $host): void {
                    $this->lost($root, $host);
                });

                return $host;
            },
            function (\Throwable $e) use ($root): never {
                unset($this->starting[$root]);

                throw $e;
            },
        );
        $this->starting[$root] = $promise;

        return $promise;
    }

    /** One event from $root's host, to the clients following it. */
    private function relay(string $root, array $envelope, string $payload): void
    {
        $sessionId = \is_string($envelope['sessionId'] ?? null) ? $envelope['sessionId'] : null;
        if ($sessionId !== null) {
            $this->bindSession($sessionId, $root);
        } elseif (\in_array($envelope['type'] ?? null, [EventType::SESSION_CREATED, EventType::SESSION_UPDATED], true)
            && \is_string($envelope['data']['id'] ?? null)) {
            $this->bindSession($envelope['data']['id'], $root);
        }

        $notification = \json_decode($payload, false, JsonRpc::MAX_DEPTH);
        if (!$notification instanceof \stdClass || !($notification->params ?? null) instanceof \stdClass) {
            return;
        }
        $notification->params->root = $root;
        $text = (string) \json_encode($notification, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE);
        $message = \json_decode($text, true, JsonRpc::MAX_DEPTH);
        $message = \is_array($message) ? $message : null;

        $targets = $sessionId === null ? ($this->rootClients[$root] ?? []) : ($this->subscribers[$sessionId] ?? []);
        $seq = ($envelope['durable'] ?? false) === true && \is_int($envelope['seq'] ?? null) ? $envelope['seq'] : null;
        foreach ($targets as $connectionId => $connection) {
            if ($seq !== null && $sessionId !== null) {
                if ($seq <= ($this->cursors[$connectionId][$sessionId] ?? 0)) {
                    continue;
                }
                $this->cursors[$connectionId][$sessionId] = $seq;
            }
            if (!$connection->isOpen()) {
                continue;
            }
            // A pinned viewer hears only its pinned sessions: a root-wide
            // (server-scope) event of a workspace is not its to see.
            if ($sessionId === null && $connection->viewerSessions() !== null) {
                continue;
            }
            if ($message === null || $seq !== null || ($envelope['durable'] ?? false) === true) {
                $this->send($connection, $text);

                continue;
            }
            // Live-only: sheddable under overflow, deltas of one part coalesce.
            $this->outbox($connection)?->pushEphemeral(
                $message,
                \in_array($envelope['type'] ?? null, [SessionEvent::ASSISTANT_DELTA, SessionEvent::REASONING_DELTA], true)
                    ? $sessionId . '|' . $envelope['type'] . '|' . ($envelope['turnId'] ?? '')
                    : null,
            );
        }
    }

    /**
     * Every gateway-emitted frame goes through the connection's {@see Outbox}
     * (the primary dispatcher's, so order is shared with its own frames and the
     * byte ceiling covers both) - never straight onto the socket.
     */
    private function send(Connection $connection, string $text): void
    {
        // Unregistered means closed: nothing queues for a gone peer.
        $this->outbox($connection)?->push($text);
    }

    private function outbox(Connection $connection): ?Outbox
    {
        return $this->primary->outboxFor($connection);
    }

    /**
     * $root's host is gone: forget it, and tell every client following one
     * of its sessions to resubscribe — which starts a fresh host.
     */
    private function lost(string $root, WorkspaceHostClient $host): void
    {
        if (($this->hosts[$root] ?? null) !== $host) {
            return;
        }
        unset($this->hosts[$root]);
        foreach ($this->sessionRoots as $sessionId => $sessionRoot) {
            if ($sessionRoot !== $root || !isset($this->subscribers[$sessionId])) {
                continue;
            }
            $text = JsonRpc::encode(EventEnvelope::server(EventType::SERVER_OVERFLOW, [
                // PHP casts all-decimal session ids to int array keys; the
                // wire contract is the string the client subscribed with.
                'sessionId' => (string) $sessionId,
                'dropped' => 0,
                'action' => 'resubscribe',
            ])->notification());
            foreach ($this->subscribers[$sessionId] as $connection) {
                if ($connection->isOpen()) {
                    $this->send($connection, $text);
                }
            }
            unset($this->subscribers[$sessionId]);
        }
    }

    /** Stop $root's host on purpose; its followers hear what a crash would tell them. */
    private function retire(string $root, string $reason): void
    {
        $host = $this->hosts[$root] ?? null;
        if ($host !== null) {
            $host->stop($reason, $this->loop);
            $this->lost($root, $host);
        }
    }

    private function dropSubscriber(string $sessionId, string $connectionId): void
    {
        unset($this->subscribers[$sessionId][$connectionId]);
        if (($this->subscribers[$sessionId] ?? []) !== []) {
            return;
        }
        unset($this->subscribers[$sessionId]);
        $root = $this->sessionRoots[$sessionId] ?? null;
        $host = $root === null ? null : ($this->hosts[$root] ?? null);
        $host?->rpc()->call('session.unsubscribe', ['sessionId' => $sessionId])->then(null, static function (): void {
        });
    }

    /** Stop the hosts nobody follows, that wait on nothing and run no turn. */
    private function recycleIdle(): void
    {
        foreach ($this->hosts as $root => $host) {
            if ($host->idleSeconds() < self::IDLE_RECYCLE_SECONDS || ($this->inFlight[$root] ?? 0) > 0) {
                continue;
            }
            foreach ($this->subscribers as $sessionId => $followers) {
                if ($followers !== [] && ($this->sessionRoots[$sessionId] ?? null) === $root) {
                    continue 2;
                }
            }
            $host->rpc()->call('server.health')->then(function (array $health) use ($root, $host): void {
                if ((int) ($health['turnsRunning'] ?? 0) === 0 && ($this->hosts[$root] ?? null) === $host
                    && $host->idleSeconds() >= self::IDLE_RECYCLE_SECONDS) {
                    $this->retire($root, 'idle');
                }
            }, static function (): void {
            });
        }
    }
}

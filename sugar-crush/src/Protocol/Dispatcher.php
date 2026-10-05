<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Protocol;

use React\EventLoop\TimerInterface;
use Ratchet\RFC6455\Messaging\Frame;
use SugarCraft\Crush\Host\BackgroundEvents;
use SugarCraft\Crush\Protocol\Methods\AgentsMethods;
use SugarCraft\Crush\Protocol\Methods\BgMethods;
use SugarCraft\Crush\Protocol\Methods\CommandMethods;
use SugarCraft\Crush\Protocol\Methods\FilesMethods;
use SugarCraft\Crush\Protocol\Methods\MemoryMethods;
use SugarCraft\Crush\Protocol\Methods\PermissionMethods;
use SugarCraft\Crush\Protocol\Methods\ServerMethods;
use SugarCraft\Crush\Protocol\Methods\SessionMethods;
use SugarCraft\Crush\Protocol\Methods\SettingsMethods;
use SugarCraft\Crush\Protocol\Methods\TodoMethods;
use SugarCraft\Crush\Protocol\Methods\WorkspaceMethods;
use SugarCraft\Crush\Protocol\Methods\ToolMethods;
use SugarCraft\Crush\Protocol\Methods\TurnMethods;
use SugarCraft\Crush\Protocol\Schema\ProtocolSchema;
use SugarCraft\Crush\Server\ServerConfig;
use SugarCraft\Crush\Server\Ws\Connection;
use SugarCraft\Crush\Server\Ws\MessageHandler;
use SugarCraft\Crush\Server\Ws\Outbox;

/**
 * The `sugarcrush.v1` protocol (roadmap O-3b, Appendix O §6): the
 * {@see MessageHandler} the WebSocket transport hands every message to.
 *
 * WHAT A MESSAGE GOES THROUGH, in order:
 *  1. before `server.hello`, a message over {@see MAX_PRE_HELLO_BYTES} closes
 *     the socket (1009), and any request other than the hello is answered
 *     `-32002 not_initialized` and closed with {@see CLOSE_NOT_INITIALIZED};
 *  2. the JSON-RPC 2.0 decode ({@see JsonRpc}): depth-capped, id kept as sent;
 *  3. the client's request budget (50/s, burst 200) — `-32011 rate_limited`;
 *  4. the method's existence (`-32601`), the scope it needs (`-32003`), and
 *     its params against the published schema (`-32602`, naming the field);
 *  5. an `idempotencyKey` on a side-effecting method: a retry gets the
 *     original answer (5 min, 1,000 per principal — {@see IdempotencyCache});
 *  6. the method, whose {@see RpcError} becomes the error object and whose
 *     any other failure becomes `-32099 internal` without its message.
 * A notification (no `id`) runs the same way and is never answered.
 *
 * EVENTS GO OUT THROUGH EACH CLIENT'S {@see Outbox}, never straight onto the
 * socket — that is what keeps a slow client from stalling a turn (§6.9).
 *
 * THE HOST'S TICK. While the dispatcher runs, every {@see PUMP_INTERVAL_SECONDS}
 * each running turn's live events are folded ({@see ServerContext::pump()}) —
 * how a permission question reaches a client while the child waits on it —
 * and every {@see TICK_INTERVAL_SECONDS} a `server.tick` tells clients the
 * server is alive.
 *
 * BACKGROUND SESSIONS AT BOOT (roadmap O-4b). {@see start()} re-adopts the
 * `/bg` daemons an earlier server or TUI of this root left running
 * ({@see BackgroundEvents::boot()}, the server's caller of
 * `BackgroundSupervisor::reconnect()`), and from then on polls the
 * workspace's supervisor every {@see BackgroundEvents::POLL_SECONDS} for the
 * `bg.*` events.
 */
final class Dispatcher implements MessageHandler
{
    /** The protocol major this dispatcher speaks. */
    public const PROTOCOL = ServerConfig::PROTOCOL_MAJOR;

    /** Appendix O §6.2: frames before the hello are capped. */
    public const MAX_PRE_HELLO_BYTES = 65_536;

    /** Appendix O §6.1: requests a client may have in flight. */
    public const MAX_INFLIGHT = 64;

    /** Appendix O §6.1: the largest message this server sends. */
    public const MAX_SERVER_FRAME_BYTES = 4_194_304;

    /** Appendix O §6.2: `limits.tickIntervalMs`. */
    public const TICK_INTERVAL_SECONDS = 15.0;

    /** How often running turns' live events are folded. */
    public const PUMP_INTERVAL_SECONDS = 0.05;

    /** How often a drain looks whether the running turns have settled. */
    public const DRAIN_POLL_SECONDS = 0.1;

    /** Appendix O §6.2: the close code for a client that skipped the hello. */
    public const CLOSE_NOT_INITIALIZED = 4002;

    /** The principal idempotency answers are kept under: every credential today is the owner's. */
    private const OWNER = 'owner';

    /** @var array<string, Client> */
    private array $clients = [];

    private ?TimerInterface $pumpTimer = null;

    private ?TimerInterface $tickTimer = null;

    private ?TimerInterface $drainTimer = null;

    private ?TimerInterface $backgroundTimer = null;

    private bool $stopped = false;

    private function __construct(
        private readonly ServerContext $context,
        private readonly MethodRegistry $methods,
        private readonly IdempotencyCache $idempotency,
    ) {
    }

    /**
     * A dispatcher over $context answering every method of the v1 roster
     * ({@see methods()}), or $methods when given.
     */
    public static function new(ServerContext $context, ?MethodRegistry $methods = null, ?IdempotencyCache $idempotency = null): self
    {
        return new self($context, $methods ?? self::methods(), $idempotency ?? IdempotencyCache::new($context->clock()));
    }

    /** The v1 method roster, one class per namespace. */
    public static function methods(): MethodRegistry
    {
        $registry = MethodRegistry::new();
        ServerMethods::register($registry);
        SessionMethods::register($registry);
        TurnMethods::register($registry);
        PermissionMethods::register($registry);
        CommandMethods::register($registry);
        SettingsMethods::register($registry);
        MemoryMethods::register($registry);
        AgentsMethods::register($registry);
        BgMethods::register($registry);
        FilesMethods::register($registry);
        ToolMethods::register($registry);
        TodoMethods::register($registry);
        WorkspaceMethods::register($registry);

        return $registry;
    }

    public function context(): ServerContext
    {
        return $this->context;
    }

    public function registry(): MethodRegistry
    {
        return $this->methods;
    }

    /**
     * Arm the host tick and the liveness tick, re-adopt the background
     * sessions an earlier process left running, and arm their poll.
     * Idempotent.
     */
    public function start(): void
    {
        if ($this->stopped) {
            return;
        }
        $loop = $this->context->loop();
        $this->pumpTimer ??= $loop->addPeriodicTimer(self::PUMP_INTERVAL_SECONDS, fn () => $this->context->pump());
        $background = $this->context->background();
        if ($background !== null && $this->backgroundTimer === null) {
            $background->boot();
            $this->backgroundTimer = $loop->addPeriodicTimer(BackgroundEvents::POLL_SECONDS, static fn () => $background->poll());
        }
        $this->tickTimer ??= $loop->addPeriodicTimer(self::TICK_INTERVAL_SECONDS, function (): void {
            $this->context->broadcast(EventEnvelope::server(EventType::SERVER_TICK, [
                'now' => (int) \floor(($this->context->clock())() * 1000),
                'turnsRunning' => $this->context->turnsRunning(),
            ]), false);
        });
    }

    /**
     * Drain before stopping (carried from W6 to O-3b; `server.drainSeconds`):
     * from now on no turn or session is admitted (`draining`), every client
     * is told the server is going and for how long (`server.shutdown` with
     * `graceSeconds`), and $then runs once the turns in flight have settled —
     * or when $seconds have passed, whichever is first. With nothing running,
     * or $seconds at 0, $then runs now. Calling it again while a drain waits
     * cuts the wait short. The turns still running when $then runs are the
     * caller's to cancel.
     *
     * @param \Closure(bool): void $then told whether every turn settled in time
     */
    public function drain(float $seconds, \Closure $then, string $reason = 'server shutting down'): void
    {
        if ($this->drainTimer !== null) {
            $this->context->loop()->cancelTimer($this->drainTimer);
            $this->drainTimer = null;
            $then($this->context->turnsRunning() === 0);

            return;
        }

        $this->context->beginDraining();
        if ($seconds <= 0.0 || $this->context->turnsRunning() === 0) {
            $then(true);

            return;
        }

        $this->context->broadcast(EventEnvelope::server(EventType::SERVER_SHUTDOWN, ['reason' => $reason, 'graceSeconds' => $seconds]), false);
        $clock = $this->context->clock();
        $deadline = $clock() + $seconds;
        $this->drainTimer = $this->context->loop()->addPeriodicTimer(self::DRAIN_POLL_SECONDS, function () use ($then, $clock, $deadline): void {
            $idle = $this->context->turnsRunning() === 0;
            if (!$idle && $clock() < $deadline) {
                return;
            }
            if ($this->drainTimer !== null) {
                $this->context->loop()->cancelTimer($this->drainTimer);
                $this->drainTimer = null;
            }
            $then($idle);
        });
    }

    /** Whether a drain is waiting on running turns. */
    public function isDraining(): bool
    {
        return $this->drainTimer !== null;
    }

    /**
     * Tell every client the server is going (`server.shutdown`), then cancel
     * the ticks and detach every feed. The sockets are the transport's to close.
     */
    public function stop(string $reason = 'server shutting down', float $graceSeconds = 0.0): void
    {
        if ($this->stopped) {
            return;
        }
        $this->stopped = true;
        $this->context->broadcast(EventEnvelope::server(EventType::SERVER_SHUTDOWN, ['reason' => $reason, 'graceSeconds' => $graceSeconds]), false);
        $loop = $this->context->loop();
        foreach ([$this->pumpTimer, $this->tickTimer, $this->drainTimer, $this->backgroundTimer] as $timer) {
            if ($timer !== null) {
                $loop->cancelTimer($timer);
            }
        }
        $this->backgroundTimer = null;
        $this->pumpTimer = null;
        $this->tickTimer = null;
        $this->drainTimer = null;
        $this->context->dropFeeds();
    }

    // ── MessageHandler ─────────────────────────────────────────────────

    public function onOpen(Connection $connection): void
    {
        $outbox = Outbox::for(
            $connection,
            $this->context->loop(),
            static fn (int $dropped): string => JsonRpc::encode(EventEnvelope::server(EventType::SERVER_OVERFLOW, [
                'dropped' => $dropped,
                'action' => 'resubscribe',
            ])->notification()),
        );
        $client = new Client($connection, $outbox, Scope::owner(), $this->context->clock());
        $this->clients[$connection->id()] = $client;
        $this->context->addClient($client);
        $this->start();
    }

    public function onMessage(Connection $connection, string $payload): void
    {
        $client = $this->clients[$connection->id()] ?? null;
        if ($client === null) {
            return;
        }

        if (!$client->isInitialized() && \strlen($payload) > self::MAX_PRE_HELLO_BYTES) {
            $connection->close(Frame::CLOSE_TOO_BIG, 'send server.hello first');

            return;
        }

        $request = JsonRpc::decode($payload);
        if (\is_array($request)) {
            [$error, $id] = $request;
            $client->send(JsonRpc::error($id, $error));

            return;
        }

        if (!$client->admit()) {
            if ($request->hasId) {
                $client->send(JsonRpc::error($request->id, RpcError::of(ErrorCode::RateLimited, 'too many requests', null, ['retryAfterMs' => 1000])));
            }

            return;
        }

        if (!$client->isInitialized() && $request->method !== ServerMethods::HELLO) {
            if ($request->hasId) {
                $client->send(JsonRpc::error($request->id, RpcError::of(ErrorCode::NotInitialized, 'send server.hello first')));
            }
            $client->outbox()->flush();
            $connection->close(self::CLOSE_NOT_INITIALIZED, 'not initialized');

            return;
        }

        $this->call($client, $request);
    }

    public function onClose(Connection $connection, int $code): void
    {
        $client = $this->clients[$connection->id()] ?? null;
        unset($this->clients[$connection->id()]);
        if ($client !== null) {
            $client->outbox()->discard();
            $this->context->removeClient($client);
        }
    }

    // ── calls ──────────────────────────────────────────────────────────

    private function call(Client $client, Request $request): void
    {
        try {
            $spec = $this->methods->get($request->method)
                ?? throw RpcError::of(ErrorCode::MethodNotFound, \sprintf('method not found: %s', \substr($request->method, 0, 64)));
            $call = new CallContext($client, $request, $this->context, $this->methods);
            $call->requireScope($spec->scope);

            // The published schema is the contract (O-3c): params that break
            // it never reach the method. The errors name paths, never values.
            $invalid = ProtocolSchema::paramErrors($spec->name, $request->params);
            if ($invalid !== []) {
                throw RpcError::of(ErrorCode::InvalidParams, 'invalid params: ' . $invalid[0], 'invalid_params', ['errors' => \array_slice($invalid, 0, 8)]);
            }

            $params = Params::of($request->params);
            $key = $spec->sideEffects ? $params->optionalString('idempotencyKey', IdempotencyCache::MAX_KEY_LENGTH) : null;
            if ($key !== null) {
                $remembered = $this->idempotency->get(self::OWNER, $spec->name, $key);
                if ($remembered !== null) {
                    $this->answer($client, $request, $remembered['answer']);

                    return;
                }
            }

            $result = ($spec->handler)($call, $params);
            if ($key !== null) {
                $this->idempotency->put(self::OWNER, $spec->name, $key, $result);
            }
            $this->answer($client, $request, $result);
        } catch (RpcError $e) {
            if ($request->hasId) {
                $client->send(JsonRpc::error($request->id, $e));
            }
        } catch (\Throwable) {
            // The message may carry a path, a prompt or a secret: the client
            // gets the code and the kind, never the text.
            if ($request->hasId) {
                $client->send(JsonRpc::error($request->id, RpcError::of(ErrorCode::Internal, 'internal error')));
            }
        }
    }

    private function answer(Client $client, Request $request, mixed $result): void
    {
        if (!$request->hasId) {
            return;
        }
        $text = JsonRpc::result($request->id, $result);
        if (\strlen($text) > self::MAX_SERVER_FRAME_BYTES) {
            $text = JsonRpc::error($request->id, RpcError::of(
                ErrorCode::Internal,
                \sprintf('the answer is larger than %d bytes; ask for less', self::MAX_SERVER_FRAME_BYTES),
                'too_large',
            ));
        }
        $client->send($text);
    }
}

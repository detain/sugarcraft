<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host;

use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Code\Protocol\Client;
use SugarCraft\Code\Protocol\Dispatcher;
use SugarCraft\Code\Protocol\ErrorCode;
use SugarCraft\Code\Protocol\EventEnvelope;
use SugarCraft\Code\Protocol\Methods\ServerMethods;
use SugarCraft\Code\Protocol\RpcError;
use SugarCraft\Code\Protocol\Scope;
use SugarCraft\Code\Server\Workspace\Gateway;
use SugarCraft\Plugins\Host\HostServices;
use SugarCraft\Plugins\Host\RootedHostServices;
use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * The {@see HostServices} a channel plugin runs against, served by the candy-code
 * host it runs inside (PL-5c). Each call goes through {@see Dispatcher::invoke()},
 * the same gates and the same handler functions the WebSocket path runs, but with
 * no socket, no loopback dial and no JSON-RPC frame: an answer's value is taken
 * through the wire's encoding, so a bundle holds the values a wire client decodes.
 *
 * One instance is one in-process client of its host. {@see connect()} opens it
 * (the hello the wire makes first), {@see disconnect()} closes it, and events the
 * client follows reach {@see subscribeEvents()} listeners as their envelope arrays,
 * in the order the host emitted them. The feed bounds a catch-up buffer the same way
 * it does for a wire client: past the feed's catch-up cap (`SessionFeed::MAX_PRELIVE_BUFFER`
 * envelopes, or its byte cap) the events still buffered are dropped, and the listener
 * is told so by a `server.overflow` envelope with `action: resubscribe`. A bundle must
 * resubscribe from its last seq to recover them. Delivery is queued and drained on the loop, so a listener that calls back into the
 * host cannot reorder or re-enter the feed that is emitting: a drain already in progress
 * is not entered again, and the outer loop delivers whatever a listener's call queued.
 *
 * Every method is asynchronous, as the {@see HostServices} contract is (owner decision
 * 2026-11-19, option a): it returns a promise that resolves to the value the call answers
 * with, or to null for the void calls, and rejects with the {@see RpcError} the wire would
 * answer with. A call on the primary root settles before the method returns, so a caller that
 * waits on it gets the answer in the same tick. A call routed to another root settles on a later
 * tick, when its workspace host answers, so a caller must not read a routed promise synchronously.
 * Refusals are the wire's own {@see RpcError}s: the same code, kind and data.
 *
 * Five deliberate differences from the wire, recorded for the Phase 5d bundles:
 * - Each answer's value is encoded under the wire's flags and decoded back, with no
 *   envelope. That keeps the wire's UTF-8 substitution, so a bundle gets the same
 *   values on both doors, and the `too_large` refusal is computed as the frame the
 *   wire would send with a null id, not the request's id. A wire answer to a long
 *   request id can therefore cross the ceiling where this door does not. A bundle
 *   that needs byte-exact parity with the wire should keep its answers well under
 *   {@see Dispatcher::MAX_SERVER_FRAME_BYTES}. An answer nested 63 to 511 levels is
 *   refused `too_deep` here: the bundle's wire client (sugar-mcp-client's
 *   `JsonRpc::MAX_DEPTH`, 64 with the envelope) drops that frame undecoded, and the
 *   call times out there instead. An answer nested 512 or more does not encode under
 *   the wire's flags at all, so it is refused `internal` (the kind any unexpected
 *   failure gets), not `too_deep`.
 * - Rate meters are not spent. {@see Dispatcher::invoke()} skips the per-client
 *   `rate_limited` budgets the wire applies to each frame, because the caller is the
 *   host itself, so a bundle's own loop is never refused `rate_limited`.
 * - Follow results: {@see subscribeFollowResults()} listeners are never fired here.
 *   The wire fires them only from the reconnect re-follow, and this door has no
 *   re-follow. The first follow's answer is the return value of
 *   {@see subscribeSession()}, which is the only delivery a bundle needs.
 * - A reconnect follows nothing. {@see disconnect()} then {@see connect()} opens a new
 *   client with no feeds: {@see followedSessions()} is empty, and no event of a session is
 *   delivered until the bundle calls {@see subscribeSession()} for it again. This door tells
 *   no listener that its follows were lost, unlike the wire's reconnect re-follow, so a
 *   bundle that reconnects must re-follow each session it still wants. The
 *   {@see subscribeEvents()} listeners survive the reconnect.
 * - Request-side refusals are the schema's, not the transport's. There is no frame, so
 *   the wire's pre-hello ceiling ({@see Dispatcher::MAX_PRE_HELLO_BYTES}) and frame
 *   ceiling ({@see \SugarCraft\Code\Server\ServerConfig::MAX_CLIENT_FRAME_BYTES}), which
 *   close the socket with `CLOSE_TOO_BIG`, do not apply, and neither do the id, method
 *   and params-shape checks of JsonRpc::decode. A request the wire would close the socket
 *   for is refused here by its published schema instead: a `text` past
 *   `ServerConfig::MAX_CLIENT_MESSAGE_BYTES` is `invalid_params`. Invalid UTF-8 is
 *   `parse_error` on both doors.
 *
 * Routing (PL-5c-A; the design is reviews/PL-5c-A-sink-design.md). A call names its root by
 * `root` (the two root forms, {@see createSessionIn()} and {@see pendingPermissionsIn()}) or by a
 * `sessionId` the gateway bound to one. The door asks {@see Gateway::rootFor()}, the rule the wire
 * uses: a call with no root goes to the primary dispatcher, as before. A call routed to another
 * root goes through {@see Gateway::callRoot()} to that root's workspace host, over the gateway's
 * child socket. That hop is inside the host, not a loopback dial: the door still builds no socket
 * and no JSON-RPC frame. A routed answer takes the wire's encode gate ({@see Dispatcher::wireAnswer()})
 * before it reaches a bundle, so it is refused on the same limits as a primary answer.
 *
 * Each routed call has its own timer ({@see CALL_TIMEOUT_SECONDS}): on expiry the caller gets a
 * `timeout` refusal, and a late answer is dropped after the gateway's bookkeeping. A subscription
 * or session answered after the door gave up, or after the client left, is compensated with a
 * session.unsubscribe or a session.delete, so the host keeps no state no caller holds (design 4.1).
 * Events of a routed session reach the {@see subscribeEvents()} listeners through this client's
 * {@see InProcessEventSink}, with the `root` the gateway adds.
 */
final class InProcessHostServices implements HostServices, RootedHostServices
{
    /** The window a `session.subscribe` without an `afterSeq` pages its reset snapshot to: the bundles' own rule. */
    public const SNAPSHOT_LIMIT = 200;

    /** How long a routed call may take to answer before the caller is refused `timeout` (the bundles' own per-call timeout). */
    public const CALL_TIMEOUT_SECONDS = 20.0;

    private static int $sinkSeq = 0;

    private ?Client $client = null;

    /** @var list<\Closure(array<string, mixed>): void> */
    private array $listeners = [];

    /** @var \SplQueue<EventEnvelope> */
    private \SplQueue $pending;

    /** Whether a drain is scheduled or running: enqueue() schedules at most one. */
    private bool $draining = false;

    /** Whether a drain is running right now: a drain entered from inside a listener returns at once. */
    private bool $delivering = false;

    /** The routed-event sink of this client, made when it first follows a session of another root. */
    private ?InProcessEventSink $sink = null;

    private function __construct(
        private readonly Dispatcher $dispatcher,
        private readonly LoopInterface $loop,
        private readonly ?Gateway $gateway,
    ) {
        $this->pending = new \SplQueue();
    }

    /**
     * An in-process host over $dispatcher, whose events are drained on $loop. With $gateway,
     * calls and events of sessions on other roots route through it (PL-5c-A); without one, the
     * door serves the primary root only.
     */
    public static function new(Dispatcher $dispatcher, LoopInterface $loop, ?Gateway $gateway = null): self
    {
        return new self($dispatcher, $loop, $gateway);
    }

    /**
     * The factory the serve hands each channel bundle (PL-5d): one in-process client per bundle,
     * all over the primary root's dispatcher, routed through $gateway when one is given.
     *
     * @return \Closure(string): HostServices
     */
    public static function bundleFactory(Dispatcher $dispatcher, LoopInterface $loop, ?Gateway $gateway = null): \Closure
    {
        return static fn (string $bundle): HostServices => self::new($dispatcher, $loop, $gateway);
    }

    /**
     * Open the in-process client: the hello the wire makes first. Resolves once
     * the host has answered it; a hello that is refused rejects with its RpcError, and any
     * other failure while opening the client rejects with the throwable itself.
     */
    public function connect(): PromiseInterface
    {
        // A failure opening the client (the host's boot, not only a refused hello) rejects too,
        // so a bundle's start() takes its retry and give-up path instead of a synchronous throw.
        return $this->answer(function (): null {
            if (!$this->openNow()) {
                $this->helloNow();
            }

            return null;
        });
    }

    /**
     * Close the in-process client. Events it was already owed are delivered first (the primary
     * feed's and the routed sink's), then the client leaves the host, which drops its feed
     * subscriptions and its routed subscriptions. Called from a listener, the owed events are
     * delivered by the drain already running (in order, to every listener) and the client still
     * closes here. A later connect() starts with no follows (see the class docblock).
     */
    public function disconnect(): PromiseInterface
    {
        return $this->answer(function (): null {
            $client = $this->client;
            if ($client === null) {
                return null;
            }
            $this->drain();
            $sink = $this->sink;
            if ($sink !== null) {
                $sink->flush();
                $sink->close();
                $this->gateway?->detachTarget($sink);
                $this->sink = null;
            }
            // A listener that disconnected from inside the flush has already left.
            if ($this->client !== $client) {
                return null;
            }
            $this->client = null;
            $this->dispatcher->context()->removeClient($client);

            return null;
        });
    }

    /** Whether the in-process client is open and has said hello. */
    public function isConnected(): PromiseInterface
    {
        return $this->answer(fn (): bool => $this->openNow());
    }

    /**
     * Mirrors server.hello (inventory row 1). Opens the client on first use; a
     * second hello on the same client is the wire's `already_initialized` conflict.
     */
    public function hello(): PromiseInterface
    {
        return $this->answer(fn (): array => $this->helloNow());
    }

    private function helloNow(): array
    {
        $context = $this->dispatcher->context();
        $client = $this->client ??= $this->open();

        return $this->dispatcher->invoke($client, ServerMethods::HELLO, [
            'minProtocol' => Dispatcher::PROTOCOL,
            'maxProtocol' => Dispatcher::PROTOCOL,
            'client' => ['name' => 'candy-code.in-process', 'version' => $context->version()],
            'caps' => [],
        ]);
    }

    private function openNow(): bool
    {
        return $this->client?->isInitialized() ?? false;
    }

    /**
     * Mirrors session.subscribe (row 2). A $cursor is read on every call and its value
     * is the resume point, as the wire's reconnect re-follow reads it (ServeBridge::follow in
     * scripts/channel-bridge/src/Candy/ServeBridge.php, the only surviving copy):
     * a re-follow after a `server.overflow` notice resumes from the seq the bundle has
     * applied, not from the $afterSeq it first followed with. $afterSeq is the resume
     * point only when no cursor is given. A cursor of null or 0 opens from a snapshot.
     */
    public function subscribeSession(string $sessionId, ?int $afterSeq, ?\Closure $cursor): PromiseInterface
    {
        return $this->answer(function () use ($sessionId, $afterSeq, $cursor): PromiseInterface|array {
            if ($cursor !== null) {
                $resume = $cursor();
                $afterSeq = $resume === null || $resume === 0 ? null : $resume;
            }

            $params = ['sessionId' => $sessionId];
            if ($afterSeq !== null) {
                $params['afterSeq'] = $afterSeq;
            } else {
                $params['snapshotLimit'] = self::SNAPSHOT_LIMIT;
            }

            $client = $this->connected();
            $root = $this->routeOf(['sessionId' => $sessionId]);
            if ($root === null) {
                return $this->dispatcher->invoke($client, 'session.subscribe', $params);
            }

            return $this->subscribeRouted($root, $sessionId, $afterSeq, $params);
        });
    }

    /**
     * Mirrors session.unsubscribe (row 3). Best-effort like the wire wrapper: once the
     * client has disconnected there is nothing left to unfollow, so this returns quietly
     * rather than throwing not-initialized.
     */
    public function unsubscribeSession(string $sessionId): PromiseInterface
    {
        return $this->answer(function () use ($sessionId): null {
            if (!$this->openNow()) {
                return null;
            }
            $client = $this->connected();
            if ($this->routeOf(['sessionId' => $sessionId]) !== null) {
                if ($this->sink !== null) {
                    $this->gateway?->detachSubscriber($sessionId, $this->sink);
                    $this->sink->unfollow($sessionId);
                }

                return null;
            }
            $this->dispatcher->invoke($client, 'session.unsubscribe', ['sessionId' => $sessionId]);

            return null;
        });
    }

    /** Mirrors session.send (row 4). */
    public function sendTurn(
        string $sessionId,
        string $text,
        string $delivery,
        ?string $idempotencyKey,
        array $images,
        array $documents,
    ): PromiseInterface {
        return $this->answer(function () use ($sessionId, $text, $delivery, $idempotencyKey, $images, $documents): PromiseInterface|array {
            $params = ['sessionId' => $sessionId, 'text' => $text, 'delivery' => $delivery];
            if ($idempotencyKey !== null) {
                $params['idempotencyKey'] = $idempotencyKey;
            }
            if ($images !== []) {
                $params['images'] = $images;
            }
            if ($documents !== []) {
                $params['documents'] = $documents;
            }

            return $this->bySession('session.send', $sessionId, $params);
        });
    }

    /** Mirrors session.create (row 5). The primary root only: a bundle asks for another root with {@see createSessionIn()}. */
    public function createSession(?string $name, ?string $permissionMode): PromiseInterface
    {
        return $this->answer(fn (): array => $this->dispatcher->invoke(
            $this->connected(),
            'session.create',
            $this->createParams($name, $permissionMode),
        ));
    }

    /**
     * The root form of {@see createSession()} (PL-5c-A, section 2.6): the session is created in
     * $root, through its workspace host when $root is not the primary root. The id is bound to
     * that root, so later calls find it by `sessionId` alone.
     *
     * Caveat (section 2.6): with no gateway the primary answer omits `root`, since the caller's
     * root is never checked. A non-primary root with no gateway also runs on the primary
     * dispatcher, a latent misroute recorded in section 12 (no production caller passes a null gateway).
     */
    public function createSessionIn(string $root, ?string $name, ?string $permissionMode): PromiseInterface
    {
        return $this->answer(function () use ($root, $name, $permissionMode): PromiseInterface|array {
            $client = $this->connected();
            $params = $this->createParams($name, $permissionMode);
            $routed = $this->routeOf(['root' => $root]);
            if ($routed === null) {
                return $this->primaryAnswer($this->dispatcher->invoke($client, 'session.create', $params));
            }

            return $this->routedCall($routed, 'session.create', $params, '', self::wire(...), function (mixed $answer) use ($routed): void {
                // A late answer names a session the bundle never received: delete it, so a retry creates one.
                if ($answer instanceof \stdClass && \is_string($answer->id ?? null)) {
                    $this->gateway?->callQuietly($routed, 'session.delete', (object) ['sessionId' => $answer->id]);
                }
            });
        });
    }

    /** Mirrors permission.respond (row 6). */
    public function respondPermission(string $sessionId, string $askId, string $reply, ?string $note): PromiseInterface
    {
        return $this->answer(function () use ($sessionId, $askId, $reply, $note): PromiseInterface|array {
            $params = ['sessionId' => $sessionId, 'askId' => $askId, 'reply' => $reply];
            if ($note !== null) {
                $params['note'] = $note;
            }

            return $this->bySession('permission.respond', $sessionId, $params);
        });
    }

    /** Mirrors session.cancel (row 7). */
    public function cancelSession(string $sessionId, string $mode): PromiseInterface
    {
        return $this->answer(fn (): PromiseInterface|array => $this->bySession(
            'session.cancel',
            $sessionId,
            ['sessionId' => $sessionId, 'mode' => $mode],
        ));
    }

    /** Mirrors session.get (row 8). */
    public function sessionSnapshot(string $sessionId, int $limit): PromiseInterface
    {
        return $this->answer(fn (): PromiseInterface|array => $this->bySession(
            'session.get',
            $sessionId,
            ['sessionId' => $sessionId, 'limit' => $limit],
        ));
    }

    /** Mirrors command.exec (row 9). */
    public function execCommand(string $sessionId, string $name, string $args): PromiseInterface
    {
        return $this->answer(function () use ($sessionId, $name, $args): PromiseInterface|array {
            // The wire refuses `args: ""` as invalid params; an empty argument list is an absent key.
            $params = ['sessionId' => $sessionId, 'name' => $name];
            if ($args !== '') {
                $params['args'] = $args;
            }

            return $this->bySession('command.exec', $sessionId, $params);
        });
    }

    /** Mirrors permission.pending (row 10) on the primary root. */
    public function pendingPermissions(): PromiseInterface
    {
        return $this->answer(fn (): array => $this->dispatcher->invoke($this->connected(), 'permission.pending', []));
    }

    /**
     * The root form of {@see pendingPermissions()} (PL-5c-A, section 2.6): the pending asks of
     * $root's workspace host, with the top-level `root` set. Items carry no `root` of their own.
     *
     * Caveat (section 2.6): with no gateway the primary answer omits the top-level `root`, as for
     * {@see createSessionIn()}. A non-primary root with no gateway runs on the primary dispatcher
     * (latent, section 12).
     */
    public function pendingPermissionsIn(string $root): PromiseInterface
    {
        return $this->answer(function () use ($root): PromiseInterface|array {
            $client = $this->connected();
            $routed = $this->routeOf(['root' => $root]);
            if ($routed === null) {
                return $this->primaryAnswer($this->dispatcher->invoke($client, 'permission.pending', []));
            }

            return $this->routedCall($routed, 'permission.pending', [], '', self::wire(...), static function (): void {
            });
        });
    }

    /**
     * Row 11: each event the followed sessions emit reaches the listener as its envelope
     * array, {@see EventEnvelope::toArray()}: the params of the wire's `event` notification
     * (PL-5d, decision c). A routed session's events carry `root` as well.
     */
    public function subscribeEvents(\Closure $listener): PromiseInterface
    {
        $this->listeners[] = $listener;

        return resolve(null);
    }

    /**
     * Row 12: accepted so a bundle registers at construction, and never fired. The wire fires follow results only on a
     * reconnect re-follow, which this door has no path to (see the class docblock). The first follow's answer is
     * {@see subscribeSession()}'s return value.
     */
    public function subscribeFollowResults(\Closure $listener): PromiseInterface
    {
        return resolve(null);
    }

    /**
     * Row 15: the session ids this client follows, the primary feeds' first, then the routed
     * sessions in the order this client subscribed to them.
     *
     * @return list<string>
     */
    public function followedSessions(): PromiseInterface
    {
        return $this->answer(function (): array {
            $ids = [];
            if ($this->client !== null) {
                $ids = \array_values(\array_map(
                    static fn ($feed): string => $feed->sessionId(),
                    $this->dispatcher->context()->followedBy($this->client),
                ));
            }
            foreach ($this->sink?->followedSessions() ?? [] as $sessionId) {
                if (!\in_array($sessionId, $ids, true)) {
                    $ids[] = $sessionId;
                }
            }

            return $ids;
        });
    }

    /**
     * The root $key names (`root` or `sessionId`) when the gateway routes it to another root,
     * or null for the primary dispatcher. Throws the gateway's refusal (root_not_found, invalid_params).
     *
     * @param array<string, mixed> $key
     */
    private function routeOf(array $key): ?string
    {
        return $this->gateway?->rootFor((object) $key);
    }

    /**
     * A call keyed by its session: the primary dispatcher when the gateway binds no other root to
     * the session, the routed call otherwise.
     *
     * @param array<string, mixed> $params
     */
    private function bySession(string $method, string $sessionId, array $params): PromiseInterface|array
    {
        $client = $this->connected();
        $root = $this->routeOf(['sessionId' => $sessionId]);
        if ($root === null) {
            return $this->dispatcher->invoke($client, $method, $params);
        }

        return $this->routedCall($root, $method, $params, $sessionId, self::wire(...), static function (): void {
        });
    }

    /**
     * session.subscribe on a routed session: the call answers, then this client's sink follows the
     * session from the cursor the answer names. A subscription answered after the door gave up, or
     * after the client left, is compensated instead (design 4.1).
     *
     * @param array<string, mixed> $params
     */
    private function subscribeRouted(string $root, string $sessionId, ?int $afterSeq, array $params): PromiseInterface
    {
        $sink = $this->sinkFor();

        return $this->routedCall(
            $root,
            'session.subscribe',
            $params,
            $sessionId,
            fn (mixed $answer, int $generation): mixed => $this->subscribeAnswer($root, $sessionId, $afterSeq, $sink, $answer, $generation),
            function (mixed $answer, int $generation) use ($root, $sessionId): void {
                $this->compensateSubscribe($root, $sessionId, $generation);
            },
        );
    }

    /**
     * The answer a routed subscribe settles with: converted for the bundle, then its sink follows the
     * session. A conversion refusal, or a sink that has closed or been replaced, is compensated instead.
     */
    private function subscribeAnswer(string $root, string $sessionId, ?int $afterSeq, InProcessEventSink $sink, mixed $answer, int $generation): mixed
    {
        $gateway = $this->gateway ?? throw new \LogicException('a routed call needs the gateway');
        try {
            $wire = Dispatcher::wireAnswer($answer);
        } catch (\Throwable $e) {
            $this->compensateSubscribe($root, $sessionId, $generation);

            throw $e;
        }
        if ($this->sink !== $sink || !$sink->isOpen()) {
            $this->compensateSubscribe($root, $sessionId, $generation);

            return $wire;
        }
        $throughSeq = $answer instanceof \stdClass ? (int) ($answer->throughSeq ?? 0) : 0;
        // The root's server-scope events reach the sink only once the subscribe is answered: a refused or timed-out one leaves no row.
        $gateway->useRoot($root, $sink);
        $gateway->attachSubscriber($sessionId, $sink);
        $sink->follow($sessionId, $afterSeq ?? $throughSeq);

        return $wire;
    }

    /**
     * Sends the session.unsubscribe a late subscribe needs, when no later subscribe is in flight
     * for the session and no subscriber records it (design 4.1). Otherwise the host subscription
     * belongs to a later call, and nothing is sent.
     */
    private function compensateSubscribe(string $root, string $sessionId, int $generation): void
    {
        if ($this->gateway === null
            || $this->gateway->subscribeGeneration($sessionId) !== $generation
            || $this->gateway->hasSubscribers($sessionId)) {
            return;
        }
        $this->gateway->callQuietly($root, 'session.unsubscribe', (object) ['sessionId' => $sessionId]);
    }

    /**
     * One call to $root's workspace host through the gateway, settled by exactly one of: the answer,
     * the gateway's refusal, or the door's own {@see CALL_TIMEOUT_SECONDS} timer. $onAnswer converts the
     * answer for the bundle (or throws its refusal); $onLate runs when the answer lands after the
     * timer already refused the caller.
     *
     * @param array<string, mixed> $params
     * @param \Closure(mixed, int): mixed $onAnswer
     * @param \Closure(mixed, int): void $onLate
     */
    private function routedCall(string $root, string $method, array $params, string $sessionId, \Closure $onAnswer, \Closure $onLate): PromiseInterface
    {
        $gateway = $this->gateway ?? throw new \LogicException('a routed call needs the gateway');
        $deferred = new Deferred();
        $settled = false;
        $timer = null;
        $settle = function () use (&$settled, &$timer): bool {
            if ($settled) {
                return false;
            }
            $settled = true;
            if ($timer !== null) {
                $this->loop->cancelTimer($timer);
            }

            return true;
        };
        $timer = $this->loop->addTimer(self::CALL_TIMEOUT_SECONDS, function () use ($settle, $deferred, $method): void {
            if ($settle()) {
                $deferred->reject(RpcError::of(
                    ErrorCode::Busy,
                    \sprintf('%s got no answer in %ds', $method, (int) self::CALL_TIMEOUT_SECONDS),
                    'timeout',
                ));
            }
        });

        // The first routed call registers this client's sink as a client of the root, as forward() does for
        // a connection, so the root's server-scope events reach it (design 3.5). A subscribe registers its
        // sink only once it is answered (subscribeRouted), so a refused one leaves no row (design 4.1).
        if ($method !== 'session.subscribe') {
            $gateway->useRoot($root, $this->sinkFor());
        }
        $promise = $gateway->callRoot($root, $method, (object) $params);
        $generation = $gateway->subscribeGeneration($sessionId);
        $promise->then(
            function (mixed $answer) use ($settle, $deferred, $onAnswer, $onLate, $generation): void {
                if (!$settle()) {
                    $onLate($answer, $generation);

                    return;
                }
                try {
                    $deferred->resolve($onAnswer($answer, $generation));
                } catch (\Throwable $e) {
                    $deferred->reject($e);
                }
            },
            function (\Throwable $e) use ($settle, $deferred): void {
                if ($settle()) {
                    $deferred->reject($e);
                }
            },
        );

        return $deferred->promise();
    }

    /** The routed-answer conversion of a call that needs nothing else from its answer. */
    private static function wire(mixed $answer): mixed
    {
        return Dispatcher::wireAnswer($answer);
    }

    /**
     * The primary answer of a root form carries `root`, as a routed answer does (PL-5c-A design,
     * section 2.6): the canonical primary root from the gateway. Without a gateway no canonical root
     * is known, and the root the caller named was never checked against anything, so the answer
     * names none rather than assert a root the session may not be on.
     *
     * @param array<string, mixed> $answer
     *
     * @return array<string, mixed>
     */
    private function primaryAnswer(array $answer): array
    {
        $primary = $this->gateway?->primaryRoot();
        if ($primary !== null) {
            $answer['root'] ??= $primary;
        }

        return $answer;
    }

    /** @return array<string, mixed> */
    private function createParams(?string $name, ?string $permissionMode): array
    {
        $params = [];
        if ($name !== null) {
            $params['name'] = $name;
        }
        if ($permissionMode !== null) {
            $params['permissionMode'] = $permissionMode;
        }

        return $params;
    }

    /** The routed sink of this client, made on first use. */
    private function sinkFor(): InProcessEventSink
    {
        $gateway = $this->gateway ?? throw new \LogicException('a routed call needs the gateway');

        return $this->sink ??= new InProcessEventSink(
            'inproc:' . ++self::$sinkSeq,
            $gateway,
            $this->loop,
            function (array $envelope): void {
                $this->fanOutToListeners($envelope);
            },
        );
    }

    /**
     * Hands one envelope to every listener. A listener that closes the client stops the rest of
     * this envelope's loop, so no routed event reaches a listener after the client is gone.
     *
     * @param array<string, mixed> $envelope
     */
    private function fanOutToListeners(array $envelope): void
    {
        foreach ($this->listeners as $listener) {
            if ($this->sink === null || !$this->sink->isOpen()) {
                return;
            }
            try {
                $listener($envelope);
            } catch (\Throwable $e) {
                $this->dispatcher->context()->logError($e, 'in-process event listener failed');
            }
        }
    }

    /**
     * Opens the in-process client. Arms the dispatcher's timers too (idempotent): the
     * pump, the server tick and the background poll belong to the host, so this door
     * must not depend on a socket client or ServeStartup having started it.
     */
    private function open(): Client
    {
        $this->dispatcher->start();
        $context = $this->dispatcher->context();
        $client = new Client(
            InProcessPeer::new(),
            null,
            Scope::owner(),
            $context->clock(),
            function (EventEnvelope $envelope): void {
                $this->enqueue($envelope);
            },
        );
        $context->addClient($client);

        return $client;
    }

    /** The open client; a call before connect() is the wire's not-initialized refusal. */
    private function connected(): Client
    {
        return $this->client ?? throw RpcError::of(ErrorCode::NotInitialized, 'send server.hello first');
    }

    /**
     * Runs one call and settles its promise: the answer resolves, and any throwable (a refusal
     * or a failure the host raised) rejects with the same instance the synchronous call threw.
     * The call may return a promise of a routed answer, which the result adopts.
     *
     * @param \Closure(): mixed $call
     */
    private function answer(\Closure $call): PromiseInterface
    {
        try {
            return resolve($call());
        } catch (\Throwable $e) {
            return reject($e);
        }
    }

    private function enqueue(EventEnvelope $envelope): void
    {
        $this->pending->enqueue($envelope);
        if ($this->draining) {
            return;
        }
        $this->draining = true;
        $this->loop->futureTick(fn () => $this->tick());
    }

    /**
     * One loop tick of the primary feed's delivery: at most DELIVERY_BUDGET envelopes, then re-armed
     * as a zero-delay timer while owed. The vendored FutureTickQueue runs only the callbacks counted
     * when its tick() starts, so a future tick queued here also waits for the next iteration; either
     * re-arm delivers one budget per iteration. The timer departs from design 3.3 (recorded in
     * reviews/PL-5c-A-sink-design.md, section 12).
     */
    private function tick(bool $again = false): void
    {
        $this->drain(InProcessEventSink::DELIVERY_BUDGET);
        if ($this->pending->isEmpty()) {
            $this->draining = false;

            return;
        }
        $this->loop->addTimer(0.0, fn () => $this->tick(true));
    }

    /**
     * Hand the queued events to every listener, in emit order, up to $budget of them (all when
     * null); a throwing listener is logged and the rest still hear it.
     *
     * Not re-entrant: a listener that disconnects reaches here while the outer loop is
     * still delivering, and returning at once keeps the queue in one order for every
     * listener. The outer loop picks up what the listener's call queued.
     */
    private function drain(?int $budget = null): void
    {
        if ($this->delivering) {
            return;
        }
        $this->delivering = true;
        try {
            $delivered = 0;
            while (!$this->pending->isEmpty() && ($budget === null || $delivered < $budget)) {
                $delivered++;
                $envelope = $this->pending->dequeue();
                // The wire encodes empty data as an object; in process it is an empty array, which the
                // router reads the same way (PL-5d, decision c).
                $wire = $envelope->toArray();
                if ($wire['data'] instanceof \stdClass) {
                    $wire['data'] = [];
                }
                foreach ($this->listeners as $listener) {
                    try {
                        $listener($wire);
                    } catch (\Throwable $e) {
                        $this->dispatcher->context()->logError($e, 'in-process event listener failed');
                    }
                }
            }
        } finally {
            $this->delivering = false;
        }
    }
}

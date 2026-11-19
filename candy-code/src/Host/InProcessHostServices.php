<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host;

use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use SugarCraft\Code\Protocol\Client;
use SugarCraft\Code\Protocol\Dispatcher;
use SugarCraft\Code\Protocol\ErrorCode;
use SugarCraft\Code\Protocol\EventEnvelope;
use SugarCraft\Code\Protocol\Methods\ServerMethods;
use SugarCraft\Code\Protocol\RpcError;
use SugarCraft\Code\Protocol\Scope;
use SugarCraft\Plugins\Host\HostServices;
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
 * client follows reach {@see subscribeEvents()} listeners as {@see EventEnvelope}s,
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
 * answer with. The promise is settled before the method returns, so a caller that waits on
 * it gets the answer in the same tick. Refusals are the wire's own {@see RpcError}s: the
 * same code, kind and data.
 *
 * Five deliberate differences from the wire, recorded for the Phase 5d bundles:
 * - Each answer's value is encoded under the wire's flags and decoded back, with no
 *   envelope. That keeps the wire's UTF-8 substitution, so a bundle gets the same
 *   values on both doors, and the `too_large` refusal is computed as the frame the
 *   wire would send with a null id, not the request's id. A wire answer to a long
 *   request id can therefore cross the ceiling where this door does not. A bundle
 *   that needs byte-exact parity with the wire should keep its answers well under
 *   {@see Dispatcher::MAX_SERVER_FRAME_BYTES}. An answer nested 63 levels or more is
 *   refused `too_deep` here: the bundle's wire client (sugar-mcp-client's
 *   `JsonRpc::MAX_DEPTH`, 64 with the envelope) drops that frame undecoded, and the
 *   call times out there instead.
 * - Session calls are served by the primary host's dispatcher only. This door never
 *   sends `root`, and the wire's workspace routing (`root` and sessionId-only requests
 *   through {@see \SugarCraft\Code\Server\Workspace\Gateway::routeOf()}) is not
 *   applied, so a session the gateway bound to a non-primary root is not found here.
 *   Reaching it in-process is open (PL-5c, see plan_plugin.md).
 * - Rate meters are not spent. {@see Dispatcher::invoke()} skips the per-client
 *   `rate_limited` budgets the wire applies to each frame, because the caller is the
 *   host itself, so a bundle's own loop is never refused `rate_limited`.
 * - Follow results: {@see subscribeFollowResults()} listeners are never fired here.
 *   The wire fires them only from the reconnect re-follow, and this door never
 *   reconnects. The first follow's answer is the return value of
 *   {@see subscribeSession()}, which is the only delivery a bundle needs.
 * - Request-side refusals are the schema's, not the transport's. There is no frame, so
 *   the wire's pre-hello ceiling ({@see Dispatcher::MAX_PRE_HELLO_BYTES}) and frame
 *   ceiling ({@see \SugarCraft\Code\Server\ServerConfig::MAX_CLIENT_FRAME_BYTES}), which
 *   close the socket with `CLOSE_TOO_BIG`, do not apply, and neither do the id, method
 *   and params-shape checks of JsonRpc::decode. A request the wire would close the socket
 *   for is refused here by its published schema instead: a `text` past
 *   `ServerConfig::MAX_CLIENT_MESSAGE_BYTES` is `invalid_params`. Invalid UTF-8 is
 *   `parse_error` on both doors.
 */
final class InProcessHostServices implements HostServices
{
    /** The window a `session.subscribe` without an `afterSeq` pages its reset snapshot to: the bundles' own rule. */
    public const SNAPSHOT_LIMIT = 200;

    private ?Client $client = null;

    /** @var list<\Closure(EventEnvelope): void> */
    private array $listeners = [];

    /** @var \SplQueue<EventEnvelope> */
    private \SplQueue $pending;

    /** Whether a drain is scheduled or running: enqueue() schedules at most one. */
    private bool $draining = false;

    /** Whether a drain is running right now: a drain entered from inside a listener returns at once. */
    private bool $delivering = false;

    private function __construct(
        private readonly Dispatcher $dispatcher,
        private readonly LoopInterface $loop,
    ) {
        $this->pending = new \SplQueue();
    }

    /** An in-process host over $dispatcher, whose events are drained on $loop. */
    public static function new(Dispatcher $dispatcher, LoopInterface $loop): self
    {
        return new self($dispatcher, $loop);
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
     * Close the in-process client. Events it was already owed are delivered first,
     * then the client leaves the host, which drops its feed subscriptions. Called
     * from a listener, the owed events are delivered by the drain already running
     * (in order, to every listener) and the client still closes here.
     */
    public function disconnect(): PromiseInterface
    {
        return $this->answer(function (): null {
            $client = $this->client;
            if ($client === null) {
                return null;
            }
            $this->drain();
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
     * is the resume point, as the wire's reconnect re-follow reads it (ServeBridge::follow):
     * a re-follow after a `server.overflow` notice resumes from the seq the bundle has
     * applied, not from the $afterSeq it first followed with. $afterSeq is the resume
     * point only when no cursor is given. A cursor of null or 0 opens from a snapshot.
     */
    public function subscribeSession(string $sessionId, ?int $afterSeq, ?\Closure $cursor): PromiseInterface
    {
        return $this->answer(function () use ($sessionId, $afterSeq, $cursor): array {
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

            return $this->dispatcher->invoke($this->connected(), 'session.subscribe', $params);
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
            $this->dispatcher->invoke($this->connected(), 'session.unsubscribe', ['sessionId' => $sessionId]);

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
        return $this->answer(function () use ($sessionId, $text, $delivery, $idempotencyKey, $images, $documents): array {
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

            return $this->dispatcher->invoke($this->connected(), 'session.send', $params);
        });
    }

    /** Mirrors session.create (row 5). */
    public function createSession(?string $name, ?string $permissionMode): PromiseInterface
    {
        return $this->answer(function () use ($name, $permissionMode): array {
            $params = [];
            if ($name !== null) {
                $params['name'] = $name;
            }
            if ($permissionMode !== null) {
                $params['permissionMode'] = $permissionMode;
            }

            return $this->dispatcher->invoke($this->connected(), 'session.create', $params);
        });
    }

    /** Mirrors permission.respond (row 6). */
    public function respondPermission(string $sessionId, string $askId, string $reply, ?string $note): PromiseInterface
    {
        return $this->answer(function () use ($sessionId, $askId, $reply, $note): array {
            $params = ['sessionId' => $sessionId, 'askId' => $askId, 'reply' => $reply];
            if ($note !== null) {
                $params['note'] = $note;
            }

            return $this->dispatcher->invoke($this->connected(), 'permission.respond', $params);
        });
    }

    /** Mirrors session.cancel (row 7). */
    public function cancelSession(string $sessionId, string $mode): PromiseInterface
    {
        return $this->answer(fn (): array => $this->dispatcher->invoke(
            $this->connected(),
            'session.cancel',
            ['sessionId' => $sessionId, 'mode' => $mode],
        ));
    }

    /** Mirrors session.get (row 8). */
    public function sessionSnapshot(string $sessionId, int $limit): PromiseInterface
    {
        return $this->answer(fn (): array => $this->dispatcher->invoke(
            $this->connected(),
            'session.get',
            ['sessionId' => $sessionId, 'limit' => $limit],
        ));
    }

    /** Mirrors command.exec (row 9). */
    public function execCommand(string $sessionId, string $name, string $args): PromiseInterface
    {
        return $this->answer(function () use ($sessionId, $name, $args): array {
            // The wire refuses `args: ""` as invalid params; an empty argument list is an absent key.
            $params = ['sessionId' => $sessionId, 'name' => $name];
            if ($args !== '') {
                $params['args'] = $args;
            }

            return $this->dispatcher->invoke($this->connected(), 'command.exec', $params);
        });
    }

    /** Mirrors permission.pending (row 10). */
    public function pendingPermissions(): PromiseInterface
    {
        return $this->answer(fn (): array => $this->dispatcher->invoke($this->connected(), 'permission.pending', []));
    }

    /**
     * Row 11: each event the followed sessions emit reaches the listener as its
     * {@see EventEnvelope}; {@see EventEnvelope::notification()} is the wire shape
     * for a listener that wants it.
     */
    public function subscribeEvents(\Closure $listener): PromiseInterface
    {
        $this->listeners[] = $listener;

        return resolve(null);
    }

    /**
     * Row 12: accepted so a bundle registers at construction, and never fired. The wire
     * fires follow results only on a reconnect re-follow, which this door has no path to
     * (see the class docblock). The first follow's answer is {@see subscribeSession()}'s
     * return value.
     */
    public function subscribeFollowResults(\Closure $listener): PromiseInterface
    {
        return resolve(null);
    }

    /**
     * Row 15: the session ids this client follows, in the order the host lists them.
     *
     * @return list<string>
     */
    public function followedSessions(): PromiseInterface
    {
        return $this->answer(function (): array {
            if ($this->client === null) {
                return [];
            }

            return \array_values(\array_map(
                static fn ($feed): string => $feed->sessionId(),
                $this->dispatcher->context()->followedBy($this->client),
            ));
        });
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
        $this->loop->futureTick(fn () => $this->drain());
    }

    /**
     * Hand every queued event to every listener, in emit order; a throwing listener is logged and the rest still hear it.
     *
     * Not re-entrant: a listener that disconnects reaches here while the outer loop is
     * still delivering, and returning at once keeps the queue in one order for every
     * listener. The outer loop picks up what the listener's call queued.
     */
    private function drain(): void
    {
        if ($this->delivering) {
            return;
        }
        $this->delivering = true;
        try {
            while (!$this->pending->isEmpty()) {
                $envelope = $this->pending->dequeue();
                foreach ($this->listeners as $listener) {
                    try {
                        $listener($envelope);
                    } catch (\Throwable $e) {
                        $this->dispatcher->context()->logError($e, 'in-process event listener failed');
                    }
                }
            }
        } finally {
            $this->delivering = false;
            $this->draining = false;
        }
    }
}

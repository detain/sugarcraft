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
 * no socket, no loopback dial and no JSON-RPC framing.
 *
 * One instance is one in-process client of its host. {@see connect()} opens it
 * (the hello the wire makes first), {@see disconnect()} closes it, and events the
 * client follows reach {@see subscribeEvents()} listeners as {@see EventEnvelope}s,
 * in the order the host emitted them, with none dropped: delivery is queued and
 * drained on the loop, so a listener that calls back into the host cannot reorder
 * or re-enter the feed that is emitting.
 *
 * Refusals are the wire's own {@see RpcError}s: the same code, kind and data.
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

    private bool $draining = false;

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
     * the host has answered it; a hello that is refused rejects with its RpcError.
     */
    public function connect(): PromiseInterface
    {
        if ($this->isConnected()) {
            return resolve(null);
        }
        try {
            $this->hello();
        } catch (RpcError $e) {
            return reject($e);
        }

        return resolve(null);
    }

    /**
     * Close the in-process client. Events it was already owed are delivered first,
     * then the client leaves the host, which drops its feed subscriptions.
     */
    public function disconnect(): void
    {
        $client = $this->client;
        if ($client === null) {
            return;
        }
        $this->drain();
        $this->client = null;
        $this->dispatcher->context()->removeClient($client);
    }

    public function isConnected(): bool
    {
        return $this->client?->isInitialized() ?? false;
    }

    /**
     * Mirrors server.hello (inventory row 1). Opens the client on first use; a
     * second hello on the same client is the wire's `already_initialized` conflict.
     */
    public function hello(): array
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

    /**
     * Mirrors session.subscribe (row 2). $cursor is accepted for the interface and
     * never called: the host keeps each subscriber's own sequence, so the resume
     * point is the $afterSeq the caller holds.
     */
    public function subscribeSession(string $sessionId, ?int $afterSeq, ?\Closure $cursor): array
    {
        $params = ['sessionId' => $sessionId];
        if ($afterSeq !== null) {
            $params['afterSeq'] = $afterSeq;
        } else {
            $params['snapshotLimit'] = self::SNAPSHOT_LIMIT;
        }

        return $this->dispatcher->invoke($this->connected(), 'session.subscribe', $params);
    }

    /** Mirrors session.unsubscribe (row 3). */
    public function unsubscribeSession(string $sessionId): void
    {
        $this->dispatcher->invoke($this->connected(), 'session.unsubscribe', ['sessionId' => $sessionId]);
    }

    /** Mirrors session.send (row 4). */
    public function sendTurn(
        string $sessionId,
        string $text,
        string $delivery,
        ?string $idempotencyKey,
        array $images,
        array $documents,
    ): array {
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
    }

    /** Mirrors session.create (row 5). */
    public function createSession(?string $name, ?string $permissionMode): array
    {
        $params = [];
        if ($name !== null) {
            $params['name'] = $name;
        }
        if ($permissionMode !== null) {
            $params['permissionMode'] = $permissionMode;
        }

        return $this->dispatcher->invoke($this->connected(), 'session.create', $params);
    }

    /** Mirrors permission.respond (row 6). */
    public function respondPermission(string $sessionId, string $askId, string $reply, ?string $note): array
    {
        $params = ['sessionId' => $sessionId, 'askId' => $askId, 'reply' => $reply];
        if ($note !== null) {
            $params['note'] = $note;
        }

        return $this->dispatcher->invoke($this->connected(), 'permission.respond', $params);
    }

    /** Mirrors session.cancel (row 7). */
    public function cancelSession(string $sessionId, string $mode): array
    {
        return $this->dispatcher->invoke($this->connected(), 'session.cancel', ['sessionId' => $sessionId, 'mode' => $mode]);
    }

    /** Mirrors session.get (row 8). */
    public function sessionSnapshot(string $sessionId, int $limit): array
    {
        return $this->dispatcher->invoke($this->connected(), 'session.get', ['sessionId' => $sessionId, 'limit' => $limit]);
    }

    /** Mirrors command.exec (row 9). */
    public function execCommand(string $sessionId, string $name, string $args): array
    {
        return $this->dispatcher->invoke($this->connected(), 'command.exec', ['sessionId' => $sessionId, 'name' => $name, 'args' => $args]);
    }

    /** Mirrors permission.pending (row 10). */
    public function pendingPermissions(): array
    {
        return $this->dispatcher->invoke($this->connected(), 'permission.pending', []);
    }

    /**
     * Row 11: each event the followed sessions emit reaches the listener as its
     * {@see EventEnvelope}; {@see EventEnvelope::notification()} is the wire shape
     * for a listener that wants it.
     */
    public function subscribeEvents(\Closure $listener): void
    {
        $this->listeners[] = $listener;
    }

    /**
     * Row 12 has no serve method. The wire carries a follow result only as the
     * `session.subscribe` answer itself, so there is nothing for a listener to
     * receive here: the caller gets it from {@see subscribeSession()}.
     *
     * @throws RpcError always, with the wire's method-not-found code
     */
    public function subscribeFollowResults(\Closure $listener): void
    {
        throw RpcError::of(
            ErrorCode::MethodNotFound,
            'no serve method delivers follow results; the session.subscribe answer carries them',
            'no_serve_method',
        );
    }

    /**
     * Row 15: the session ids this client follows, in the order the host lists them.
     *
     * @return list<string>
     */
    public function followedSessions(): array
    {
        if ($this->client === null) {
            return [];
        }

        return \array_values(\array_map(
            static fn ($feed): string => $feed->sessionId(),
            $this->dispatcher->context()->followedBy($this->client),
        ));
    }

    private function open(): Client
    {
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

    private function enqueue(EventEnvelope $envelope): void
    {
        $this->pending->enqueue($envelope);
        if ($this->draining) {
            return;
        }
        $this->draining = true;
        $this->loop->futureTick(fn () => $this->drain());
    }

    /** Hand every queued event to every listener, in emit order; a throwing listener is logged and the rest still hear it. */
    private function drain(): void
    {
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
            $this->draining = false;
        }
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Host;

use Ratchet\RFC6455\Messaging\CloseFrameChecker;
use Ratchet\RFC6455\Messaging\Frame;
use Ratchet\RFC6455\Messaging\FrameInterface;
use Ratchet\RFC6455\Messaging\MessageBuffer;
use Ratchet\RFC6455\Messaging\MessageInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Stream\DuplexStreamInterface;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Permissions\PermissionReply;
use SugarCraft\Crush\Protocol\Dispatcher;
use SugarCraft\Crush\Protocol\ErrorCode;
use SugarCraft\Crush\Protocol\EventEnvelope;
use SugarCraft\Crush\Protocol\JsonRpc;
use SugarCraft\Crush\Protocol\RpcError;

use function React\Promise\reject;

/**
 * The client side of a `sugarcrush.v1` endpoint: the {@see SessionHost}
 * surface a TUI attached to a running `sugarcrush serve` drives, spoken as
 * JSON-RPC over the connection (roadmap O-8a, Appendix O §4.9).
 *
 * WHAT IT CARRIES. One WebSocket-framed duplex stream — a TCP socket after the
 * HTTP upgrade {@see \SugarCraft\Crush\Cli\Attach::connect()} makes, or the
 * UNIX socket a workspace host listens on
 * ({@see \SugarCraft\Crush\Server\Workspace\WorkspaceHostClient}) — framed as
 * a CLIENT frames it: every frame it sends is masked, every frame it reads is
 * not (RFC 6455 §5.1). On top of that: requests with their answers matched by
 * id ({@see call()}), the server's `event` notifications handed to every
 * {@see onEvent()} listener, and the end of the connection to every
 * {@see onClose()} one, with each call still waiting rejected.
 *
 * WHAT A SESSION MEANS HERE. {@see open()} picks the session this client
 * follows — a new one, or an existing one by id, name or unique id prefix —
 * and subscribes to it from a snapshot, so {@see history()} is the server's
 * transcript and the events that follow are its live tail. The turn verbs
 * ({@see submit()}, {@see cancel()}, {@see answerPermission()}) act on it;
 * {@see \SugarCraft\Crush\Backend\RemoteBackend} turns them into a
 * {@see \SugarCraft\Crush\Backend} a Chat can run its turns on.
 *
 * THE LAUNCH'S ATTACHMENT. A process runs at most one attached session, and
 * the parts of the launch that would otherwise open it locally — the session
 * the TUI opens ({@see \SugarCraft\Crush\Cli\Bootstrap::openSession()}) and
 * the single-writer lock Chat takes on it — ask {@see forLaunch()} first: the
 * server holds that session's lock, and the transcript is the server's.
 *
 * MUTABLE ON PURPOSE: it is the live state of one connection.
 */
final class RemoteSessionHost
{
    /** What `server.hello` names this client as. */
    public const CLIENT_NAME = 'sugarcrush-attach';

    /** How many sessions {@see open()} reads when it resolves a target. */
    public const RESOLVE_PAGE = 200;

    private static ?self $launch = null;

    private readonly MessageBuffer $buffer;

    /** @var array<string, Deferred> request id => the answer's deferred */
    private array $pending = [];

    /** @var array<string, true> request ids answered as decoded objects ({@see callPreserving()}) */
    private array $preserving = [];

    private int $nextId = 0;

    /** @var array<int, \Closure(array<string, mixed>, string): void> */
    private array $listeners = [];

    /** @var array<int, \Closure(string): void> */
    private array $closeListeners = [];

    private int $nextListener = 0;

    private bool $open = true;

    private ?string $closeReason = null;

    /** @var array<string, mixed>|null */
    private ?array $hello = null;

    private ?string $sessionId = null;

    private ?string $sessionName = null;

    /** @var array<string, mixed> */
    private array $snapshot = [];

    private int $lastSeq = 0;

    private function __construct(
        private readonly DuplexStreamInterface $stream,
        private readonly string $clientName,
    ) {
        // The server's 1012-1014 are real close codes ("try again later" is
        // what backpressure closes with); ratchet's checker predates them.
        $checker = new class () extends CloseFrameChecker {
            public function __invoke(int $val): bool
            {
                return ($val >= 1000 && $val <= 1014 && !\in_array($val, [1004, 1005, 1006], true)) || parent::__invoke($val);
            }
        };
        $this->buffer = new MessageBuffer(
            $checker,
            fn (MessageInterface $message) => $this->receive($message->getPayload()),
            fn (FrameInterface $frame) => $this->control($frame),
            false,
            null,
            Dispatcher::MAX_SERVER_FRAME_BYTES,
            Dispatcher::MAX_SERVER_FRAME_BYTES,
            function (string $bytes): void {
                if ($this->open) {
                    $this->stream->write($bytes);
                }
            },
        );

        $stream->on('data', function (string $data): void {
            if ($this->open) {
                try {
                    $this->buffer->onData($data);
                } catch (\Throwable $e) {
                    $this->gone('the server sent a frame that could not be read: ' . $e->getMessage());
                }
            }
        });
        $stream->on('close', fn () => $this->gone('the connection to the server closed'));
        $stream->on('error', fn (\Throwable $e) => $this->gone('the connection to the server failed: ' . $e->getMessage()));
    }

    /**
     * A client over $stream, which is already past any handshake: what it
     * reads next are server frames. $initial is whatever the handshake read
     * beyond its own end — the first frames, when the server was quick.
     */
    public static function overStream(DuplexStreamInterface $stream, string $clientName = self::CLIENT_NAME, string $initial = ''): self
    {
        $host = new self($stream, $clientName);
        if ($initial !== '') {
            $host->buffer->onData($initial);
        }

        return $host;
    }

    // ── the launch's attachment ─────────────────────────────────────────

    /** Make $host the session this launch is attached to (null detaches). */
    public static function useForLaunch(?self $host): void
    {
        self::$launch = $host;
    }

    /** The session this launch is attached to, or null when it runs its own. */
    public static function forLaunch(): ?self
    {
        return self::$launch;
    }

    /** Whether this launch drives $sessionId through a server. */
    public static function isAttachedTo(?string $sessionId): bool
    {
        return $sessionId !== null && self::$launch !== null && self::$launch->sessionId === $sessionId;
    }

    // ── the connection ──────────────────────────────────────────────────

    public function isOpen(): bool
    {
        return $this->open;
    }

    /** Why the connection ended, once it has. */
    public function closeReason(): ?string
    {
        return $this->closeReason;
    }

    /**
     * Send `$method` with $params and answer its result — or reject with the
     * server's {@see RpcError} (its code, `data.kind` and data intact), or
     * with a \RuntimeException when the connection is gone.
     *
     * @param array<string, mixed> $params
     *
     * @return PromiseInterface<array<string, mixed>>
     */
    public function call(string $method, array $params = []): PromiseInterface
    {
        if (!$this->open) {
            return reject(new \RuntimeException($this->closeReason ?? 'not connected to a server'));
        }

        $id = 'a' . ++$this->nextId;
        $deferred = new Deferred();
        $this->pending[$id] = $deferred;
        $this->buffer->sendMessage(JsonRpc::encode([
            'jsonrpc' => JsonRpc::VERSION,
            'id' => $id,
            'method' => $method,
            'params' => $params === [] ? new \stdClass() : $params,
        ]));

        return $deferred->promise();
    }

    /**
     * {@see call()}, answered with the result decoded as OBJECTS (`\stdClass`
     * for every JSON object), so a relay that re-encodes it hands its own
     * client exactly the shape the server sent — an empty `{}` stays an
     * object rather than becoming `[]`. The workspace gateway forwards calls
     * this way ({@see \SugarCraft\Crush\Server\Workspace\Gateway}).
     *
     * @param array<string, mixed>|\stdClass $params
     *
     * @return PromiseInterface<mixed>
     */
    public function callPreserving(string $method, array|\stdClass $params): PromiseInterface
    {
        if (!$this->open) {
            return reject(new \RuntimeException($this->closeReason ?? 'not connected to a server'));
        }

        $id = 'a' . ++$this->nextId;
        $deferred = new Deferred();
        $this->pending[$id] = $deferred;
        $this->preserving[$id] = true;
        $this->buffer->sendMessage(JsonRpc::encode([
            'jsonrpc' => JsonRpc::VERSION,
            'id' => $id,
            'method' => $method,
            'params' => $params === [] ? new \stdClass() : $params,
        ]));

        return $deferred->promise();
    }

    /**
     * Hear every `event` notification: the envelope's fields
     * (`sessionId`, `seq`, `type`, `turnId`, `durable`, `data`), and the raw
     * notification text beside it for a listener that relays it. Answers the
     * detach.
     *
     * @param \Closure(array<string, mixed>, string): void $listener
     *
     * @return \Closure(): void
     */
    public function onEvent(\Closure $listener): \Closure
    {
        $id = $this->nextListener++;
        $this->listeners[$id] = $listener;

        return function () use ($id): void {
            unset($this->listeners[$id]);
        };
    }

    /**
     * Hear the connection end, with why. Called at once when it already has.
     *
     * @param \Closure(string): void $listener
     *
     * @return \Closure(): void
     */
    public function onClose(\Closure $listener): \Closure
    {
        if (!$this->open) {
            $listener((string) $this->closeReason);

            return static function (): void {
            };
        }
        $id = $this->nextListener++;
        $this->closeListeners[$id] = $listener;

        return function () use ($id): void {
            unset($this->closeListeners[$id]);
        };
    }

    /** End the connection with a normal close. Idempotent. */
    public function close(string $reason = 'client closing'): void
    {
        if (!$this->open) {
            return;
        }
        $this->buffer->sendFrame($this->buffer->newCloseFrame(Frame::CLOSE_NORMAL, \substr($reason, 0, 123)));
        $this->stream->end();
        $this->gone($reason);
    }

    // ── the handshake and the session ───────────────────────────────────

    /**
     * `server.hello`, once: answers the server's handshake result (protocol,
     * server version, root, limits, principal).
     *
     * @return PromiseInterface<array<string, mixed>>
     */
    public function hello(): PromiseInterface
    {
        if ($this->hello !== null) {
            return \React\Promise\resolve($this->hello);
        }

        return $this->call('server.hello', [
            'minProtocol' => Dispatcher::PROTOCOL,
            'maxProtocol' => Dispatcher::PROTOCOL,
            'client' => ['name' => $this->clientName, 'version' => \SugarCraft\Crush\Cli\Help::versionString(), 'instanceId' => 'pid-' . \getmypid()],
            'caps' => ['deltas'],
        ])->then(function (array $result): array {
            $this->hello = $result;

            return $result;
        });
    }

    /** The handshake's answer, once {@see hello()} has had it. @return array<string, mixed>|null */
    public function helloResult(): ?array
    {
        return $this->hello;
    }

    /** The root the server serves, as its handshake named it. */
    public function serverRoot(): ?string
    {
        $root = $this->hello['server']['root'] ?? null;

        return \is_string($root) && $root !== '' ? $root : null;
    }

    /**
     * Pick the session this client follows and subscribe to it from a
     * snapshot: a new one when $target is null, else the session whose id or
     * name is $target, or whose id is the one starting with it. Rejects with a
     * not-found {@see RpcError} when nothing matches, and `ambiguous` when a
     * prefix matches more than one.
     *
     * @return PromiseInterface<self>
     */
    public function open(?string $target = null): PromiseInterface
    {
        $chosen = $target === null || \trim($target) === ''
            ? $this->call('session.create')
            : $this->call('session.list', ['query' => $target, 'limit' => self::RESOLVE_PAGE])
                ->then(static fn (array $page): array => self::resolveTarget((array) ($page['items'] ?? []), $target));

        return $chosen->then(function (array $summary): PromiseInterface {
            $id = (string) ($summary['id'] ?? '');

            return $this->call('session.subscribe', ['sessionId' => $id])->then(function (array $subscribed) use ($id, $summary): self {
                $this->sessionId = $id;
                $this->sessionName = \is_string($summary['name'] ?? null) && $summary['name'] !== '' ? $summary['name'] : null;
                $this->snapshot = \is_array($subscribed['snapshot'] ?? null) ? $subscribed['snapshot'] : [];
                $this->lastSeq = (int) ($subscribed['throughSeq'] ?? 0);

                return $this;
            });
        });
    }

    /**
     * The session among $items that $target names: an exact id, then an exact
     * name, then the one id that starts with it.
     *
     * @param list<mixed> $items `session.list` summaries
     *
     * @return array<string, mixed>
     *
     * @throws RpcError not found, or `ambiguous`
     */
    public static function resolveTarget(array $items, string $target): array
    {
        $rows = \array_values(\array_filter($items, 'is_array'));
        foreach (['id', 'name'] as $field) {
            foreach ($rows as $row) {
                if (($row[$field] ?? null) === $target) {
                    return $row;
                }
            }
        }
        $prefixed = \array_values(\array_filter($rows, static fn (array $row): bool => \str_starts_with((string) ($row['id'] ?? ''), $target)));
        if (\count($prefixed) === 1) {
            return $prefixed[0];
        }
        if ($prefixed === []) {
            throw RpcError::notFound(\sprintf('the server has no session "%s"', $target), 'session_not_found');
        }

        throw RpcError::of(ErrorCode::Conflict, \sprintf(
            '"%s" starts %d session ids on the server: %s',
            $target,
            \count($prefixed),
            \implode(', ', \array_map(static fn (array $row): string => (string) $row['id'], $prefixed)),
        ), 'ambiguous');
    }

    /** The session {@see open()} chose, once it has. */
    public function sessionId(): ?string
    {
        return $this->sessionId;
    }

    public function sessionName(): ?string
    {
        return $this->sessionName;
    }

    /** The highest durable seq this client has seen for its session. */
    public function lastSeq(): int
    {
        return $this->lastSeq;
    }

    /** The snapshot the subscription started from. @return array<string, mixed> */
    public function snapshot(): array
    {
        return $this->snapshot;
    }

    /**
     * The session's transcript as the server held it when this client
     * subscribed — the rows a TUI paints first.
     *
     * @return list<Message>
     */
    public function history(): array
    {
        $rows = [];
        foreach ((array) ($this->snapshot['messages'] ?? []) as $row) {
            if (\is_array($row)) {
                $rows[] = TranscriptStore::reviveRow($row);
            }
        }

        return $rows;
    }

    // ── the turn verbs ──────────────────────────────────────────────────

    /**
     * `session.send`: start a turn with $text, or queue / steer / interrupt
     * the running one ($delivery). Answers the admission
     * (`{admitted, turnId?, queueId?, …}`).
     *
     * @return PromiseInterface<array<string, mixed>>
     */
    public function submit(string $text, string $delivery = 'queue'): PromiseInterface
    {
        return $this->call('session.send', [
            'sessionId' => $this->requireSession(),
            'text' => $text,
            'delivery' => $delivery,
            'idempotencyKey' => \bin2hex(\random_bytes(12)),
        ]);
    }

    /**
     * `session.cancel`: stop the running turn ($turnId, when known), hard or
     * soft at its next step boundary.
     *
     * @return PromiseInterface<array<string, mixed>>
     */
    public function cancel(?string $turnId = null, string $mode = 'hard'): PromiseInterface
    {
        return $this->call('session.cancel', \array_filter([
            'sessionId' => $this->requireSession(),
            'turnId' => $turnId,
            'mode' => $mode,
        ], static fn (mixed $value): bool => $value !== null));
    }

    /**
     * `permission.respond`: answer the open question $askId. The first answer
     * any client gives wins; a later one is refused `already_resolved`.
     *
     * @return PromiseInterface<array<string, mixed>>
     */
    public function answerPermission(string $askId, PermissionReply $reply, ?string $note = null): PromiseInterface
    {
        return $this->call('permission.respond', \array_filter([
            'sessionId' => $this->requireSession(),
            'askId' => $askId,
            'reply' => $reply->value,
            'note' => $note !== null && $note !== '' ? $note : null,
        ], static fn (mixed $value): bool => $value !== null));
    }

    // ── internals ───────────────────────────────────────────────────────

    private function requireSession(): string
    {
        return $this->sessionId ?? throw new \LogicException('open() a session first');
    }

    private function receive(string $payload): void
    {
        try {
            $message = \json_decode($payload, true, JsonRpc::MAX_DEPTH, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return;
        }
        if (!\is_array($message)) {
            return;
        }

        if (($message['method'] ?? null) === EventEnvelope::METHOD && \is_array($message['params'] ?? null)) {
            $envelope = $message['params'];
            if (($envelope['sessionId'] ?? null) === $this->sessionId && \is_int($envelope['seq'] ?? null)) {
                $this->lastSeq = \max($this->lastSeq, $envelope['seq']);
            }
            foreach ($this->listeners as $listener) {
                try {
                    $listener($envelope, $payload);
                } catch (\Throwable) {
                    // One listener's failure is its own; the others still hear.
                }
            }

            return;
        }

        $id = $message['id'] ?? null;
        if (!\is_string($id) || !isset($this->pending[$id])) {
            return;
        }
        $deferred = $this->pending[$id];
        $preserve = isset($this->preserving[$id]);
        unset($this->pending[$id], $this->preserving[$id]);

        if (\array_key_exists('result', $message)) {
            if ($preserve) {
                $objects = \json_decode($payload, false, JsonRpc::MAX_DEPTH);
                $deferred->resolve(\is_object($objects) && \property_exists($objects, 'result') ? $objects->result : new \stdClass());

                return;
            }
            $deferred->resolve(\is_array($message['result']) ? $message['result'] : []);

            return;
        }

        $error = \is_array($message['error'] ?? null) ? $message['error'] : [];
        $data = \is_array($error['data'] ?? null) ? $error['data'] : [];
        $kind = \is_string($data['kind'] ?? null) ? $data['kind'] : null;
        unset($data['kind']);
        $deferred->reject(RpcError::of(
            ErrorCode::tryFrom((int) ($error['code'] ?? 0)) ?? ErrorCode::Internal,
            \is_string($error['message'] ?? null) ? $error['message'] : 'the server refused the request',
            $kind,
            $data,
        ));
    }

    private function control(FrameInterface $frame): void
    {
        switch ($frame->getOpcode()) {
            case Frame::OP_PING:
                $this->buffer->sendFrame($this->buffer->newFrame($frame->getPayload(), true, Frame::OP_PONG));
                break;
            case Frame::OP_CLOSE:
                $payload = $frame->getPayload();
                $code = \strlen($payload) >= 2 ? (int) \unpack('n', \substr($payload, 0, 2))[1] : Frame::CLOSE_NORMAL;
                $reason = \strlen($payload) > 2 ? \substr($payload, 2) : '';
                if ($this->open) {
                    $this->buffer->sendFrame($this->buffer->newCloseFrame($code === Frame::CLOSE_NO_STATUS ? Frame::CLOSE_NORMAL : $code));
                    $this->stream->end();
                }
                $this->gone(\sprintf('the server closed the connection (%d%s)', $code, $reason !== '' ? ': ' . $reason : ''));
                break;
        }
    }

    private function gone(string $reason): void
    {
        if (!$this->open) {
            return;
        }
        $this->open = false;
        $this->closeReason = $reason;

        $pending = $this->pending;
        $this->pending = [];
        $this->preserving = [];
        foreach ($pending as $deferred) {
            $deferred->reject(new \RuntimeException($reason));
        }
        $listeners = $this->closeListeners;
        $this->closeListeners = [];
        foreach ($listeners as $listener) {
            try {
                $listener($reason);
            } catch (\Throwable) {
            }
        }
        $this->stream->close();
    }
}

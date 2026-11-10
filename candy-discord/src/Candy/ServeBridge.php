<?php

/**
 * ServeBridge: the bridge's client of a running `candy-code serve`,
 * speaking `candy-code.v1` over the WebSocket exactly like `candy-code
 * attach` does — the same dial (`Attach::stream()`-shaped upgrade, the same
 * rfc6455 frame machine with the server's 4 MiB frame ceiling, the same
 * hello handshake, the same durable-cursor `session.subscribe` semantics).
 *
 * WHY NOT `RemoteSessionHost` (the product's own client class). It is
 * deliberately ONE session per connection: a single `$sessionId`,
 * `requireSession()` on every verb, a launch-scoped static, and `open()`
 * which subscribes by snapshot, not by cursor. A channel bridge follows N
 * conversations on ONE write-scope connection and must resume every one of
 * them from its persisted seq — the protocol supports that natively (each
 * `SessionFeed` tracks subscriptions per client; `subscribe` takes
 * `afterSeq`), and only the host's single-session facade stands in the
 * way. So the bridge reuses everything BELOW the host — `Attach::stream`,
 * the ratchet frame machine, `ServerConfig`, `DiscoveryFile`, `StateDir`,
 * `TokenStore` — and speaks JSON-RPC itself. That is the honest version of
 * "reuse the product stack": the transport is the product's; the session
 * model is the bridge's, because the bridge is multi-conversation.
 *
 * Auth door: `subprotocol` offers `candy-code.auth.<token>` on the upgrade
 * (AuthMiddleware reads it server-side); `bearer` sends the Authorization
 * header exactly like attach. Either way `candy-code.v1` must be offered —
 * the server 426s without it (Ws/Upgrade.php:56-60) and echoes it.
 *
 * MUTABLE ON PURPOSE: it is the live state of one connection.
 */

declare(strict_types=1);

namespace CandyCode\Bundles\Discord\Candy;

use CandyCode\Bundles\Discord\Support\Log;
use Ratchet\RFC6455\Messaging\CloseFrameChecker;
use Ratchet\RFC6455\Messaging\Frame;
use Ratchet\RFC6455\Messaging\FrameInterface;
use Ratchet\RFC6455\Messaging\MessageBuffer;
use Ratchet\RFC6455\Messaging\MessageInterface;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;
use React\Stream\DuplexStreamInterface;
use SugarCraft\Code\Protocol\Dispatcher;
use SugarCraft\Code\Protocol\JsonRpc;
use SugarCraft\Code\Server\ServerConfig;

use function React\Promise\reject;
use function React\Promise\resolve;

final class ServeBridge
{
    /** First reconnect wait; doubles per attempt, capped (attach's ladder, widened for a daemon). */
    private const BACKOFF_BASE_SECONDS = 0.25;
    private const BACKOFF_MAX_SECONDS = 30.0;

    /** Attempts before the bridge gives up on the endpoint and fails loud. */
    public const MAX_CONNECT_ATTEMPTS = 60;

    /** No inbound byte (frame, pong, tick) within this: the socket is a zombie. */
    public const IDLE_TIMEOUT_SECONDS = 90.0;

    /** A request without its answer within this: reject the promise (the socket stays). */
    public const CALL_TIMEOUT_SECONDS = 20.0;

    private ?DuplexStreamInterface $stream = null;
    private ?MessageBuffer $buffer = null;
    private int $epoch = 0;

    /** @var array<string, array{deferred: Deferred, timer: \React\EventLoop\TimerInterface, method: string}> */
    private array $pending = [];

    /** @var list<\Closure(array<string,mixed>): void> */
    private array $listeners = [];

    /** @var list<\Closure(): void> Called after each successful hello (resume hooks). */
    private array $readyListeners = [];

    /** @var list<\Closure(string, array<string,mixed>): void> */
    private array $followResultListeners = [];

    /** @var array<string, array{afterSeq: ?int, cursor: ?\Closure}> sessions to keep followed */
    private array $followed = [];

    private bool $helloDone = false;
    private bool $stopping = false;
    private int $attempts = 0;
    private ?\React\EventLoop\TimerInterface $reconnectTimer = null;
    private ?\React\EventLoop\TimerInterface $idleTimer = null;
    private int $rpcSeq = 0;
    private ?\Closure $onDown = null;
    private ?\Closure $onGivenUp = null;

    public function __construct(
        private readonly string $wsUrl,
        private readonly string $token,
        private readonly string $authDoor,
        private readonly Log $log,
        private readonly LoopInterface $loop,
    ) {
    }

    /**
     * The `ws(s)://host:port/ws` endpoint for a serve URL, or null when the
     * value is not a URL at all. Reuses the product's own translation so a
     * `server.json` url and a browser-openable address both work as given.
     */
    public static function wsUrlFrom(string $url): ?string
    {
        return \SugarCraft\Code\Cli\Attach::websocketUrl($url);
    }

    // ── lifecycle ───────────────────────────────────────────────────────

    /**
     * Dial, handshake, and keep the connection followed. Resolves once the
     * first hello lands; a dropped socket re-dials with the ladder until
     * {@see stop()} or the attempt budget is exhausted (then it fails and
     * every onClose hears).
     *
     * @param \Closure(string $reason): void $onDown  fires when a socket dies
     * @param \Closure(string $reason): void $onGivenUp fires when the ladder ends
     */
    public function start(?\Closure $onDown = null, ?\Closure $onGivenUp = null): PromiseInterface
    {
        $this->stopping = false;
        $this->onDown = $onDown;
        $this->onGivenUp = $onGivenUp;

        return $this->dialAndBind();
    }

    public function stop(): void
    {
        $this->stopping = true;
        if ($this->reconnectTimer !== null) {
            $this->loop->cancelTimer($this->reconnectTimer);
            $this->reconnectTimer = null;
        }
        $this->endSocket('bridge stopping');
    }

    public function connected(): bool
    {
        return $this->stream !== null && $this->helloDone;
    }

    /** Every session the connection is following (after each re-hello). */
    public function followedSessions(): array
    {
        return \array_keys($this->followed);
    }

    // ── the JSON-RPC surface ────────────────────────────────────────────

    /**
     * One request, matched by id. Rejects with {@see ServeError} on an
     * error object, on timeout, or when the socket dies while it waits.
     *
     * @param array<string, mixed> $params
     */
    public function call(string $method, array $params = []): PromiseInterface
    {
        if ($this->buffer === null || (!$this->helloDone && $method !== 'server.hello')) {
            return reject(new ServeError('not_connected', "discord-bundle: not connected ({$method})"));
        }

        $id = 'b' . (++$this->rpcSeq);
        $deferred = new Deferred();
        $payload = \json_encode([
            'jsonrpc' => JsonRpc::VERSION,
            'id' => $id,
            'method' => $method,
            'params' => (object) $params,
        ], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR);

        $timer = $this->loop->addTimer(self::CALL_TIMEOUT_SECONDS, function () use ($id): void {
            if (!isset($this->pending[$id])) {
                return;
            }
            $entry = $this->pending[$id];
            unset($this->pending[$id]);
            $entry['deferred']->reject(new ServeError('timeout', "discord-bundle: {$entry['method']} got no answer in " . self::CALL_TIMEOUT_SECONDS . 's'));
        });

        $this->pending[$id] = ['deferred' => $deferred, 'timer' => $timer, 'method' => $method];
        $this->buffer->sendFrame($this->buffer->newFrame($payload, true, Frame::OP_TEXT));

        return $deferred->promise();
    }

    /**
     * `server.hello` — the gate every other method waits behind.
     */
    public function hello(): PromiseInterface
    {
        return $this->call('server.hello', [
            'minProtocol' => Dispatcher::PROTOCOL,
            'maxProtocol' => Dispatcher::PROTOCOL,
            'client' => (object) [
                'name' => 'candy-code-discord-bundle',
                'version' => '0.1',
                'instanceId' => 'pid-' . \getmypid(),
            ],
            'caps' => ['deltas'],
        ]);
    }

    /**
     * Follow $session. With $afterSeq the server REPLAYS what that cursor
     * missed (replay-on-gap); when the log can no longer reach it the answer
     * comes back `reset` with a snapshot and we re-pin the cursor to
     * throughSeq. A null $afterSeq opens from a bounded snapshot.
     *
     * @param ?\Closure(): ?int $cursor re-reads the persisted cursor before a
     *        reconnect re-subscribe, so a seq learned between the drop and
     *        the redial is honoured.
     *
     * @return PromiseInterface<array{sessionId: string, fromSeq?: int, throughSeq: int, reset?: bool, pendingAsks: list<array<string,mixed>>, snapshot?: array<string,mixed>}>
     */
    public function follow(string $sessionId, ?int $afterSeq, ?\Closure $cursor = null): PromiseInterface
    {
        $this->followed[$sessionId] = ['afterSeq' => $afterSeq, 'cursor' => $cursor];

        return $this->call('session.subscribe', \array_filter([
            'sessionId' => $sessionId,
            'afterSeq' => $afterSeq,
            'snapshotLimit' => $afterSeq === null ? 200 : null,
        ], static fn (mixed $v): bool => $v !== null));
    }

    public function unfollow(string $sessionId): void
    {
        unset($this->followed[$sessionId]);
        if ($this->connected()) {
            $this->call('session.unsubscribe', ['sessionId' => $sessionId])->then(null, fn (\Throwable $e) => $this->log->warn('unfollow_failed', ['error' => $e->getMessage()]));
        }
    }

    /**
     * `session.send` with a fresh idempotencyKey (the replay-cache contract:
     * a retry of the same key answers with the original admission, so an
     * at-least-once platform can resend without double-running a turn).
     */
    public function send(string $sessionId, string $text, string $delivery = 'queue', ?string $idempotencyKey = null, array $images = [], array $documents = []): PromiseInterface
    {
        $params = [
            'sessionId' => $sessionId,
            'text' => $text,
            'delivery' => $delivery,
            'idempotencyKey' => $idempotencyKey ?? \bin2hex(\random_bytes(12)),
        ];
        // session.send media wire (TurnMethods): lists of {name?, mime,
        // data: strict-base64}. The serve re-sniffs bytes and refuses on
        // mime mismatch; the caller decides what a refusal means (the
        // Discord face retries once without attachments and says so).
        if ($images !== []) {
            $params['images'] = $images;
        }
        if ($documents !== []) {
            $params['documents'] = $documents;
        }

        return $this->call('session.send', $params);
    }

    public function createSession(?string $name = null, ?string $permissionMode = null): PromiseInterface
    {
        return $this->call('session.create', \array_filter([
            'name' => $name,
            'permissionMode' => $permissionMode,
        ], static fn (mixed $v): bool => $v !== null));
    }

    public function respondPermission(string $sessionId, string $askId, string $reply, ?string $note = null): PromiseInterface
    {
        return $this->call('permission.respond', \array_filter([
            'sessionId' => $sessionId,
            'askId' => $askId,
            'reply' => $reply,
            'note' => $note,
        ], static fn (mixed $v): bool => $v !== null));
    }

    /** @param \Closure(array<string,mixed>): void $listener */
    public function onEvent(\Closure $listener): void
    {
        $this->listeners[] = $listener;
    }

    /**
     * Each subscribe/re-subscribe result lands here (pendingAsks re-presentation
     * is the Bridge's job after a restart or a gap).
     *
     * @param \Closure(string $sessionId, array<string,mixed> $result): void $listener
     */
    public function onFollowResult(\Closure $listener): void
    {
        $this->followResultListeners[] = $listener;
    }

    public function cancel(string $sessionId, string $mode = 'hard'): PromiseInterface
    {
        return $this->call('session.cancel', ['sessionId' => $sessionId, 'mode' => $mode]);
    }

    /** @param \Closure(): void $listener */
    public function onReady(\Closure $listener): void
    {
        $this->readyListeners[] = $listener;
    }

    // ── the socket ──────────────────────────────────────────────────────

    /** @return PromiseInterface<array{0: DuplexStreamInterface, 1: string}> */
    private function dial(): PromiseInterface
    {
        $uri = \parse_url($this->wsUrl);
        if (!\is_array($uri) || !isset($uri['host'])) {
            return reject(new ServeError('bad_url', "discord-bundle: bad serve URL {$this->wsUrl}"));
        }
        $secure = ($uri['scheme'] ?? 'ws') === 'wss';
        $port = $uri['port'] ?? ($secure ? 443 : 80);
        $host = $uri['host'];

        $key = \base64_encode(\random_bytes(16));
        $subprotocols = $this->authDoor === 'subprotocol'
            ? [ServerConfig::SUBPROTOCOL, ServerConfig::AUTH_SUBPROTOCOL_PREFIX . $this->token]
            : [ServerConfig::SUBPROTOCOL];

        $headers = "GET {$uri['path']} HTTP/1.1\r\n"
            . 'Host: ' . $host . ':' . $port . "\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . 'Sec-WebSocket-Key: ' . $key . "\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . 'Sec-WebSocket-Protocol: ' . \implode(', ', $subprotocols) . "\r\n"
            . ($this->authDoor === 'subprotocol' ? '' : 'Authorization: Bearer ' . $this->token . "\r\n")
            . "User-Agent: candy-code-discord-bundle/0.1\r\n"
            . "\r\n";

        $deferred = new Deferred();
        $connector = new Connector(['timeout' => 15.0, 'tls' => ['peer_name' => $host]], $this->loop);
        $connector->connect(($secure ? 'tls://' : 'tcp://') . (\str_contains($host, ':') && !\str_starts_with($host, '[') ? '[' . $host . ']' : $host) . ':' . $port)->then(
            function (ConnectionInterface $connection) use ($headers, $deferred, $key): void {
                $buffer = '';
                $settled = false;
                $fail = static function (string $reason) use (&$settled, $connection, $deferred): void {
                    if ($settled) {
                        return;
                    }
                    $settled = true;
                    $connection->close();
                    $deferred->reject(new ServeError('handshake_failed', "discord-bundle: {$reason}"));
                };
                $connection->on('close', static fn () => $fail('closed during handshake'));
                $onData = null;
                $onData = static function (string $chunk) use (&$buffer, &$settled, &$onData, $connection, $fail, $deferred, $key): void {
                    $buffer .= $chunk;
                    $end = \strpos($buffer, "\r\n\r\n");
                    if ($end === false) {
                        if (\strlen($buffer) > 65536) {
                            $fail('not HTTP');
                        }

                        return;
                    }
                    $connection->removeListener('data', $onData);
                    $settled = true;
                    $head = \substr($buffer, 0, $end);
                    $rest = \substr($buffer, $end + 4);
                    if (!\str_starts_with($head, 'HTTP/1.1 101') && !\str_starts_with($head, 'HTTP/1.0 101')) {
                        $status = \str_contains($head, 'HTTP/') ? \explode("\r\n", $head)[0] : 'not HTTP';
                        $fail("upgrade refused: {$status}");

                        return;
                    }
                    $accepted = \preg_match('/^Sec-WebSocket-Accept:\s*(\S+)/mi', $head, $am) === 1 ? $am[1] : '';
                    if ($accepted !== \base64_encode(\sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true))) {
                        $fail('bad Sec-WebSocket-Accept');

                        return;
                    }
                    $echo = \preg_match('/^Sec-WebSocket-Protocol:\s*(.+)$/mi', $head, $m) === 1 ? \trim($m[1]) : '';
                    if ($echo !== ServerConfig::SUBPROTOCOL) {
                        $fail("server echoed subprotocol '{$echo}', expected " . ServerConfig::SUBPROTOCOL);

                        return;
                    }
                    $deferred->resolve([$connection, $rest]);
                };
                $connection->on('data', $onData);
                $connection->write($headers);
            },
            static fn (\Throwable $e) => $deferred->reject(new ServeError('unreachable', 'discord-bundle: ' . $e->getMessage(), [], $e)),
        );

        return $deferred->promise();
    }

    /** @return PromiseInterface<true> */
    private function dialAndBind(): PromiseInterface
    {
        return $this->dial()->then(
            function (array $socket): PromiseInterface {
                $this->attempts = 0;
                $this->bind($socket[0], $socket[1]);

                return $this->hello()->then(function (array $result): true {
                    $this->helloDone = true;
                    $this->log->info('serve_hello', [
                        'protocol' => $result['protocol'] ?? null,
                        'server' => $result['server']['version'] ?? null,
                        'root' => $result['server']['root'] ?? null,
                    ]);
                    $this->armIdleWatch();
                    // (Re-)follow every session from its persisted cursor:
                    // replay-on-gap is why a daemon can restart or lose a
                    // socket and still see everything it missed.
                    foreach ($this->followed as $sessionId => $spec) {
                        $seq = $spec['cursor'] !== null ? ($spec['cursor'])() : $spec['afterSeq'];
                        $this->follow($sessionId, $seq === null || $seq === 0 ? null : $seq, $spec['cursor'])
                            ->then(
                                function (array $r) use ($sessionId): void {
                                    $this->log->info('serve_follow', [
                                        'session' => $sessionId,
                                        'fromSeq' => $r['fromSeq'] ?? null,
                                        'throughSeq' => $r['throughSeq'] ?? null,
                                        'reset' => isset($r['reset']) ? 1 : 0,
                                    ]);
                                    foreach ($this->followResultListeners as $listener) {
                                        try {
                                            $listener($sessionId, $r);
                                        } catch (\Throwable $e) {
                                            $this->log->error('follow_result_listener_failed', ['error' => $e->getMessage()]);
                                        }
                                    }
                                },
                                fn (\Throwable $e) => $this->log->warn('serve_follow_failed', ['session' => $sessionId, 'error' => $e->getMessage()]),
                            );
                    }
                    foreach ($this->readyListeners as $readyListener) {
                        try {
                            $readyListener();
                        } catch (\Throwable $e) {
                            $this->log->error('ready_listener_failed', ['error' => $e->getMessage()]);
                        }
                    }

                    return true;
                });
            },
            function (\Throwable $e): PromiseInterface {
                // The ladder lives HERE: a failed dial and a dropped socket
                // take the same path back.
                $this->helloDone = false;

                return $this->scheduleReconnect($e->getMessage());
            },
        );
    }

    private function bind(DuplexStreamInterface $stream, string $initial): void
    {
        $epoch = ++$this->epoch;
        $this->stream = $stream;
        $this->buffer = new MessageBuffer(
            self::closeFrameChecker(),
            function (MessageInterface $message) use ($epoch): void {
                if ($epoch === $this->epoch) {
                    $this->receive($message->getPayload());
                }
            },
            function (FrameInterface $frame) use ($epoch): void {
                if ($epoch === $this->epoch) {
                    $this->control($frame);
                }
            },
            false,
            null,
            Dispatcher::MAX_SERVER_FRAME_BYTES,
            Dispatcher::MAX_SERVER_FRAME_BYTES,
            function (string $bytes) use ($epoch): void {
                if ($epoch === $this->epoch && $this->stream !== null) {
                    $this->stream->write($bytes);
                }
            },
        );

        $stream->on('data', function (string $data) use ($epoch): void {
            if ($epoch !== $this->epoch || $this->buffer === null) {
                return;
            }
            $this->armIdleWatch();
            try {
                $this->buffer->onData($data);
            } catch (\Throwable $e) {
                $this->log->warn('serve_bad_frame', ['error' => $e->getMessage()]);
                $this->endSocket('bad frame');
                $this->scheduleReconnect('bad frame')->then(null, static function (): void {
                });
            }
        });
        $stream->on('close', function () use ($epoch): void {
            if ($epoch !== $this->epoch || $this->stopping) {
                return;
            }
            $this->helloDone = false;
            $this->rejectPending('socket closed');
            ($this->onDown ?? static function (): void {})('socket closed');
            $this->scheduleReconnect('socket closed')->then(null, static function (): void {
            });
        });
        $stream->on('error', function (\Throwable $e) use ($epoch): void {
            if ($epoch === $this->epoch) {
                $this->log->warn('serve_socket_error', ['error' => $e->getMessage()]);
            }
        });

        if ($initial !== '') {
            $this->buffer->onData($initial);
        }
    }

    /** @param string $payload */
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

        if (($message['method'] ?? null) === 'event' && \is_array($message['params'] ?? null)) {
            foreach ($this->listeners as $listener) {
                try {
                    $listener($message['params']);
                } catch (\Throwable $e) {
                    $this->log->error('event_listener_failed', ['error' => $e->getMessage()]);
                }
            }

            return;
        }

        $id = $message['id'] ?? null;
        if (!\is_string($id) || !isset($this->pending[$id])) {
            return; // server-pushed calls (tool.client.call) ride the event lane
        }
        $entry = $this->pending[$id];
        unset($this->pending[$id]);
        $this->loop->cancelTimer($entry['timer']);

        if (isset($message['error']) && \is_array($message['error'])) {
            $entry['deferred']->reject(ServeError::fromRpc($entry['method'], $message['error']));

            return;
        }
        $result = $message['result'] ?? null;
        $entry['deferred']->resolve(\is_array($result) ? $result : []);
    }

    private function control(FrameInterface $frame): void
    {
        switch ($frame->getOpcode()) {
            case Frame::OP_PING:
                $this->buffer?->sendFrame($this->buffer->newFrame($frame->getPayload(), true, Frame::OP_PONG));
                break;
            case Frame::OP_PONG:
                $this->armIdleWatch();
                break;
            case Frame::OP_CLOSE:
                $this->endSocket('server closed');
                break;
        }
    }

    private function armIdleWatch(): void
    {
        if ($this->idleTimer !== null) {
            $this->loop->cancelTimer($this->idleTimer);
        }
        $epoch = $this->epoch;
        $this->idleTimer = $this->loop->addTimer(self::IDLE_TIMEOUT_SECONDS, function () use ($epoch): void {
            if ($epoch !== $this->epoch || $this->stopping) {
                return;
            }
            $this->log->warn('serve_idle_reset', ['seconds' => self::IDLE_TIMEOUT_SECONDS]);
            $this->endSocket('idle');
            $this->scheduleReconnect('idle')->then(null, static function (): void {
            });
        });
    }

    private function endSocket(string $reason): void
    {
        if ($this->idleTimer !== null) {
            $this->loop->cancelTimer($this->idleTimer);
            $this->idleTimer = null;
        }
        $stream = $this->stream;
        $buffer = $this->buffer;
        $this->stream = null;
        $this->buffer = null;
        $this->helloDone = false;
        if ($buffer !== null && $stream !== null) {
            try {
                $buffer->sendFrame($buffer->newCloseFrame(Frame::CLOSE_NORMAL, \substr($reason, 0, 60)));
            } catch (\Throwable) {
                // A close that cannot be framed is already gone.
            }
        }
        $stream?->close();
        $this->rejectPending($reason);
    }

    private function rejectPending(string $reason): void
    {
        foreach ($this->pending as $entry) {
            $this->loop->cancelTimer($entry['timer']);
            $entry['deferred']->reject(new ServeError('dropped', "discord-bundle: {$entry['method']} lost the socket ({$reason})"));
        }
        $this->pending = [];
    }

    /** @return PromiseInterface<true> */
    private function scheduleReconnect(string $reason): PromiseInterface
    {
        if ($this->stopping) {
            return reject(new GiveUp('stopping'));
        }
        $this->attempts++;
        if ($this->attempts > self::MAX_CONNECT_ATTEMPTS) {
            $message = "discord-bundle: give up after {$this->attempts} attempts ({$reason})";
            $this->log->error('serve_give_up', ['attempts' => $this->attempts, 'reason' => $reason]);
            ($this->onGivenUp ?? static function (): void {})($message);

            return reject(new GiveUp($message));
        }
        $delay = \min(
            self::BACKOFF_BASE_SECONDS * (2 ** \min(8, $this->attempts - 1)),
            self::BACKOFF_MAX_SECONDS,
        );
        $this->log->info('serve_redial', ['attempt' => $this->attempts, 'delayMs' => (int) ($delay * 1000), 'reason' => $reason]);

        return new \React\Promise\Promise(function (callable $resolveP, callable $rejectP) use ($delay): void {
            $this->reconnectTimer = $this->loop->addTimer($delay, function () use ($resolveP, $rejectP): void {
                $this->reconnectTimer = null;
                $this->dialAndBind()->then($resolveP, $rejectP);
            });
        });
    }

    private static function closeFrameChecker(): CloseFrameChecker
    {
        // The same widened checker attach uses (RemoteSessionHost:193): a
        // status outside the strict rfc6455 set must not KILL a reconnecting
        // client over a cosmetic close code.
        return new class () extends CloseFrameChecker {
            public function __invoke(int $val): bool
            {
                return ($val >= 1000 && $val <= 1014 && !\in_array($val, [1004, 1005, 1006], true)) || parent::__invoke($val);
            }
        };
    }
}

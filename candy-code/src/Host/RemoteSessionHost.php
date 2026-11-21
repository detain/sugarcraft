<?php

declare(strict_types=1);

namespace SugarCraft\Code\Host;

use Ratchet\RFC6455\Messaging\CloseFrameChecker;
use Ratchet\RFC6455\Messaging\Frame;
use Ratchet\RFC6455\Messaging\FrameInterface;
use Ratchet\RFC6455\Messaging\MessageBuffer;
use Ratchet\RFC6455\Messaging\MessageInterface;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Stream\DuplexStreamInterface;
use SugarCraft\Async\AsyncOps;
use SugarCraft\Async\Backoff;
use SugarCraft\Async\CancellationSource;
use SugarCraft\Async\OperationCancelledException;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Message;
use SugarCraft\Code\Permissions\PermissionReply;
use SugarCraft\Code\Protocol\Dispatcher;
use SugarCraft\Code\Protocol\ErrorCode;
use SugarCraft\Code\Protocol\EventEnvelope;
use SugarCraft\Code\Protocol\RpcError;
use SugarCraft\Code\Support\Log;
use SugarCraft\McpClient\McpCall;
use SugarCraft\McpClient\PendingCalls;
use SugarCraft\McpClient\Protocol\JsonRpc;

use function React\Promise\reject;

/**
 * The client side of a `candy-code.v1` endpoint: the {@see SessionHost}
 * surface a TUI attached to a running `candy-code serve` drives, spoken as
 * JSON-RPC over the connection (roadmap O-8a, Appendix O §4.9).
 *
 * WHAT IT CARRIES. One WebSocket-framed duplex stream — a TCP socket after the
 * HTTP upgrade {@see \SugarCraft\Code\Cli\Attach::connect()} makes, or the
 * UNIX socket a workspace host listens on
 * ({@see \SugarCraft\Code\Server\Workspace\WorkspaceHostClient}) — framed as
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
 * {@see \SugarCraft\Code\Backend\RemoteBackend} turns them into a
 * {@see \SugarCraft\Code\Backend} a Chat can run its turns on.
 *
 * THE LAUNCH'S ATTACHMENT. A process runs at most one attached session, and
 * the parts of the launch that would otherwise open it locally — the session
 * the TUI opens ({@see \SugarCraft\Code\Cli\Bootstrap::openSession()}) and
 * the single-writer lock Chat takes on it — ask {@see forLaunch()} first: the
 * server holds that session's lock, and the transcript is the server's.
 *
 * WHEN THE SOCKET DROPS (ISS-114). A client created through
 * {@see \SugarCraft\Code\Cli\Attach::connect()} carries a re-dial closure: if
 * its stream dies, the host pauses instead of ending — requests the lost
 * socket could not answer are rejected, but listeners and the durable-event
 * cursor survive, and it redials with backoff and re-helloes with `resume`
 * from that cursor. The server replays what was missed, so a turn that was
 * running keeps streaming here rather than failing "lost the server". A
 * deliberate {@see close()}, an exhausted ladder, or a session the server
 * refuses back ends it for good, exactly as before.
 *
 * MUTABLE ON PURPOSE: it is the live state of one connection.
 */
final class RemoteSessionHost
{
    /** What `server.hello` names this client as. */
    public const CLIENT_NAME = 'candy-code-attach';

    /** How many sessions {@see open()} reads when it resolves a target. */
    public const RESOLVE_PAGE = 200;

    /**
     * Rows per snapshot page this client fetches (ISS-058): the whole
     * transcript in one answer can outgrow a server frame, so attach pages.
     */
    public const SNAPSHOT_PAGE_ROWS = 200;

    /** Most older pages {@see open()} stitches before giving up (10 000 rows). */
    public const MAX_SNAPSHOT_PAGES = 50;

    /**
     * First wait before redialing a dropped socket (ISS-114). The ladder then
     * runs from twice this, doubling per failed attempt, capped at
     * {@see RECONNECT_MAX_DELAY_SECONDS}: 0.25, 0.5, 1, 2, 4, 4, ...
     */
    public const RECONNECT_BASE_DELAY_SECONDS = 0.25;

    /** The reconnect backoff ceiling. */
    public const RECONNECT_MAX_DELAY_SECONDS = 4.0;

    /**
     * Failed attempts before the drop is final and every {@see onClose()}
     * hears it. A refused dial, a throwing dial and a resume hello that fails
     * each count once; a resume the server refuses outright does not count,
     * it ends the attachment at once.
     */
    public const RECONNECT_MAX_ATTEMPTS = 12;

    /**
     * How long a resume's server.hello may go unanswered before its attempt
     * fails, so the ladder redials. Without it a server that accepts the
     * upgrade and then stays silent parks the attachment in its reconnecting
     * state, with no verdict ever reached. The first attempt waits this long;
     * each failed attempt after it waits one base longer (10s, 20s, 30s ...),
     * so a slow but answering server is reached on a later redial. The silent
     * case is bounded by the whole ladder: the hello waits total 78 times this
     * value, and the first reconnect wait and the pauses between attempts add
     * about 36 s on top (about 816 s with the default ladder).
     */
    public const RESUME_HELLO_SECONDS = 10.0;

    private static ?self $launch = null;

    private MessageBuffer $buffer;

    private DuplexStreamInterface $stream;

    /**
     * The re-dial handed in by {@see \SugarCraft\Code\Cli\Attach::connect()}
     * (answers `[stream, initial bytes]`); null means this pipe has no way
     * back and a drop is final — the workspace relay's UNIX socket, and any
     * plain {@see overStream()} caller.
     */
    private readonly ?\Closure $reopen;

    /** Delay scheduler seam for the first wait (ISS-114 tests bound it without waiting). */
    private readonly ?\Closure $after;

    /** Redial ladder seam: the production ladder unless a test bounds its delays. */
    private readonly ?Backoff $redialBackoff;

    /** How long a resume's server.hello may stay unanswered ({@see RESUME_HELLO_SECONDS}). */
    private readonly float $resumeHelloSeconds;

    /** Cancels the redial cycle in flight; close() fires it. */
    private ?CancellationSource $cancelLadder = null;

    /** Bumped per {@see wire()}; listeners of a stale epoch are ignored. */
    private int $epoch = 0;

    /** The epoch whose death has already been counted (one verdict per socket). */
    private int $deadEpoch = 0;

    private bool $closing = false;

    private bool $reconnecting = false;

    private int $attempt = 0;

    /** The reason the ladder is running; it names the final verdict. */
    private string $dropReason = '';

    private int $snapshotPageRows = self::SNAPSHOT_PAGE_ROWS;

    /** In-flight requests by id; the "preserving" flag marks ids answered as decoded objects ({@see callPreserving()}). */
    private PendingCalls $pending;

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

    /**
     * The server's `command.list` roster — command name to its `runsIn`
     * ("server" or "client") — cached once per open (C-SES-7.f). Empty when
     * the fetch failed or has not answered yet, and an empty map means every
     * typed slash command runs locally here, which is exactly the pre-C-SES-7.f
     * behaviour; the cache is a fast-path for {@see \SugarCraft\Code\Chat}'s
     * dispatch gate, never a correctness authority. The map is frozen at the
     * moment of this attach — a command added to the server's roster after
     * that waits for the window's next attach to be seen as the server's.
     *
     * @var array<string, string>
     */
    private array $serverCommands = [];

    /** @var array<string, mixed> */
    private array $snapshot = [];

    private int $lastSeq = 0;

    /** Latch: one malformed-frame warning per stream, re-armed by wire(). */
    private bool $malformedLogged = false;

    private function __construct(
        DuplexStreamInterface $stream,
        private readonly string $clientName,
        string $initial = '',
        ?\Closure $reopen = null,
        ?\Closure $after = null,
        ?Backoff $redialBackoff = null,
        float $resumeHelloSeconds = self::RESUME_HELLO_SECONDS,
    ) {
        // Checked before the stream is wired: a resume's hello arms this timeout
        // only after it is sent, so a bad value must fail here, not mid-redial.
        // The deadline grows per failed attempt up to RECONNECT_MAX_ATTEMPTS times
        // the base, so the base is judged by that product: a base whose largest
        // deadline overflows to INF would throw on a later redial, after wire().
        if ($resumeHelloSeconds <= 0 || !is_finite($resumeHelloSeconds * self::RECONNECT_MAX_ATTEMPTS)) {
            throw new \InvalidArgumentException('resumeHelloSeconds must be a positive number of seconds whose largest grown deadline stays finite');
        }
        $this->reopen = $reopen;
        $this->after = $after;
        $this->redialBackoff = $redialBackoff;
        $this->resumeHelloSeconds = $resumeHelloSeconds;
        $this->pending = new PendingCalls();
        $this->wire($stream, $initial);
    }

    /**
     * The server's 1012-1014 are real close codes ("try again later" is
     * what backpressure closes with); ratchet's checker predates them.
     */
    private static function closeFrameChecker(): CloseFrameChecker
    {
        return new class () extends CloseFrameChecker {
            public function __invoke(int $val): bool
            {
                return ($val >= 1000 && $val <= 1014 && !\in_array($val, [1004, 1005, 1006], true)) || parent::__invoke($val);
            }
        };
    }

    /**
     * Put a socket under the frame machine (ISS-114): every listener carries
     * this wiring's epoch, so a dead socket's late events — its close firing
     * after a reconnect already moved on — are ignored, and only the current
     * stream's writes reach the server.
     */
    private function wire(DuplexStreamInterface $stream, string $initial): void
    {
        $epoch = ++$this->epoch;
        $this->malformedLogged = false;
        if (isset($this->stream) && $this->stream !== $stream) {
            // The socket this replaces is dead to the host and its epoch is stale,
            // so its events are silenced; closing it keeps it from staying attached.
            $this->stream->close();
        }
        $this->stream = $stream;
        $this->buffer = new MessageBuffer(
            self::closeFrameChecker(),
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

        $stream->on('data', function (string $data) use ($epoch): void {
            if ($epoch !== $this->epoch) {
                return;
            }
            if ($this->open) {
                try {
                    $this->buffer->onData($data);
                } catch (\Throwable $e) {
                    $this->dropped(Lang::t('host.remote.bad_frame', ['error' => $e->getMessage()]));
                }
            }
        });
        $stream->on('close', function () use ($epoch): void {
            if ($epoch === $this->epoch) {
                $this->dropped(Lang::t('host.remote.closed'));
            }
        });
        $stream->on('error', function (\Throwable $e) use ($epoch): void {
            if ($epoch === $this->epoch) {
                $this->dropped(Lang::t('host.remote.failed', ['error' => $e->getMessage()]));
            }
        });

        if ($initial !== '') {
            $this->buffer->onData($initial);
        }
    }

    /**
     * A client over $stream, which is already past any handshake: what it
     * reads next are server frames. $initial is whatever the handshake read
     * beyond its own end — the first frames, when the server was quick.
     *
     * $reopen (ISS-114) redials the endpoint and answers `[stream, initial]`
     * once the upgrade completes again; with it, a dropped socket becomes a
     * pause — the host re-helloes with `resume` from its cursor and keeps
     * following its session. Without it the drop is final, as it was.
     * $after overrides the scheduler of the first wait (tests); it defaults to
     * the loop. $redialBackoff overrides the redial ladder (tests bound its
     * delays); it keeps the RECONNECT_MAX_ATTEMPTS budget when it is omitted.
     * $resumeHelloSeconds is the base deadline of each resume's hello, grown
     * per failed attempt (tests shorten it).
     */
    public static function overStream(
        DuplexStreamInterface $stream,
        string $clientName = self::CLIENT_NAME,
        string $initial = '',
        ?\Closure $reopen = null,
        ?\Closure $after = null,
        ?Backoff $redialBackoff = null,
        float $resumeHelloSeconds = self::RESUME_HELLO_SECONDS,
    ): self {
        return new self($stream, $clientName, $initial, $reopen, $after, $redialBackoff, $resumeHelloSeconds);
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
        $this->pending->register($id, self::settling($deferred));
        // The envelope and the empty-params `{}` shim are sugar-mcp-client's
        // McpCall (rider R17b), the one tested place for both.
        $this->buffer->sendMessage(McpCall::request($id, $method, $params, \JSON_PRESERVE_ZERO_FRACTION));

        return $deferred->promise();
    }

    /**
     * {@see call()}, answered with the result decoded as OBJECTS (`\stdClass`
     * for every JSON object), so a relay that re-encodes it hands its own
     * client exactly the shape the server sent — an empty `{}` stays an
     * object rather than becoming `[]`. The workspace gateway forwards calls
     * this way ({@see \SugarCraft\Code\Server\Workspace\Gateway}).
     *
     * @param array<string, mixed>|\stdClass $params
     *
     * @return PromiseInterface<mixed>
     */
    public function callPreserving(string $method, array|\stdClass $params, ?float $deadline = null, ?\React\EventLoop\LoopInterface $loop = null): PromiseInterface
    {
        if (!$this->open) {
            return reject(new \RuntimeException($this->closeReason ?? 'not connected to a server'));
        }

        $id = 'a' . ++$this->nextId;
        $deferred = new Deferred();
        $this->pending->register($id, self::settling($deferred), true);
        $this->buffer->sendMessage(McpCall::request($id, $method, $params, \JSON_PRESERVE_ZERO_FRACTION));

        if ($deadline !== null) {
            // A host that never answers must not park this Deferred (and its
            // pending row) forever; a late answer finds no row and is dropped.
            $loop ??= Loop::get();
            $timer = $loop->addTimer($deadline, function () use ($id, $deferred, $method): void {
                // A late answer finds no row and is dropped.
                $this->pending->fail($id, new \RuntimeException(\sprintf('no answer to %s in time', \substr($method, 0, 64))));
            });
            $promise = $deferred->promise();
            $promise->then(static fn () => $loop->cancelTimer($timer), static fn () => $loop->cancelTimer($timer));

            return $promise;
        }

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

    /** End the connection with a normal close. Idempotent, and final: a
     * deliberate close never reconnects (ISS-114), even mid-backoff. */
    public function close(string $reason = 'client closing'): void
    {
        $this->closing = true;
        $this->cancelLadder?->cancel();
        if (!$this->open) {
            if ($this->reconnecting) {
                $this->gone($reason, true);
            }

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

        return $this->call('server.hello', $this->helloParams())->then(function (array $result): array {
            $this->hello = $result;

            return $result;
        });
    }

    /**
     * The handshake body: who this client is, and (on a resume, ISS-114)
     * which session to pick up from which seq, paged to the size the original
     * {@see open()} asked for.
     *
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function helloParams(array $extra = []): array
    {
        return $extra + [
            'minProtocol' => Dispatcher::PROTOCOL,
            'maxProtocol' => Dispatcher::PROTOCOL,
            'client' => ['name' => $this->clientName, 'version' => \SugarCraft\Code\Cli\Help::versionString(), 'instanceId' => 'pid-' . \getmypid()],
            'caps' => ['deltas'],
        ];
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
     * The snapshot is taken in pages (ISS-058): the newest $pageRows rows
     * arrive with the subscription, older pages are fetched by `session.get`
     * until the transcript is whole — one frame never carries a long session's
     * full history.
     *
     * @return PromiseInterface<self>
     */
    public function open(?string $target = null, int $pageRows = self::SNAPSHOT_PAGE_ROWS): PromiseInterface
    {
        $chosen = $target === null || \trim($target) === ''
            ? $this->call('session.create')
            : $this->call('session.list', ['search' => $target, 'limit' => self::RESOLVE_PAGE])
                ->then(static fn (array $page): array => self::resolveTarget((array) ($page['items'] ?? []), $target));

        return $chosen->then(function (array $summary) use ($pageRows): PromiseInterface {
            $id = (string) ($summary['id'] ?? '');
            $this->snapshotPageRows = max(1, $pageRows);

            return $this->call('session.subscribe', ['sessionId' => $id, 'snapshotLimit' => $pageRows])
                ->then(function (array $subscribed) use ($id, $summary): self {
                    $this->sessionId = $id;
                    $this->sessionName = \is_string($summary['name'] ?? null) && $summary['name'] !== '' ? $summary['name'] : null;
                    $this->adoptSnapshot((array) ($subscribed['snapshot'] ?? []), (int) ($subscribed['throughSeq'] ?? 0));

                    return $this;
                })
                ->then(fn (): PromiseInterface => $this->pageOlder($pageRows))
                // C-SES-7.f: one `command.list` per attach, cached for the
                // dispatch gate. A failed fetch stays SILENT on purpose: an
                // unfilled map keeps every typed command local — the status
                // quo the cache is an optimization of, not a change to.
                ->then(fn (): PromiseInterface => $this->call('command.list', ['sessionId' => $id]))
                ->then(
                    function (array $roster): self {
                        foreach ((array) ($roster['items'] ?? []) as $row) {
                            if (\is_array($row) && isset($row['name'], $row['runsIn'])) {
                                $this->serverCommands[(string) $row['name']] = (string) $row['runsIn'];
                            }
                        }

                        return $this;
                    },
                    function (): self {
                        return $this;
                    },
                );
        });
    }

    /**
     * Take $snapshot as the rows this client draws the session from, capped at
     * $throughSeq as the newest durable event it covers. Empty and unpaged
     * snapshots keep the wire shape byte-for-byte, so a {@see history()} read
     * of a small session looks exactly as it did before paging.
     *
     * @param array<string, mixed> $snapshot
     */
    private function adoptSnapshot(array $snapshot, int $throughSeq): void
    {
        $this->snapshot = $snapshot;
        $this->lastSeq = \max($this->lastSeq, $throughSeq);
    }

    /**
     * Fetch the pages older than the snapshot's window (ISS-058) and prepend
     * them, newest-older-first, until `hasMoreOlder` clears or the bound is
     * spent. `oldestIndex` is counted from the first row, which a concurrent
     * append never shifts, so the walk is stable while the session runs.
     *
     * @return PromiseInterface<array<string, mixed>> the whole snapshot
     */
    private function pageOlder(int $pageRows, int $pagesLeft = self::MAX_SNAPSHOT_PAGES): PromiseInterface
    {
        if ($pagesLeft <= 0 || ($this->snapshot['hasMoreOlder'] ?? false) !== true) {
            return \React\Promise\resolve($this->completeSnapshot());
        }

        return $this->call('session.get', [
            'sessionId' => $this->requireSession(),
            'limit' => $pageRows,
            'beforeIndex' => (int) ($this->snapshot['oldestIndex'] ?? 0),
        ])->then(function (array $page) use ($pageRows, $pagesLeft): PromiseInterface {
            $older = \array_values(\array_filter((array) ($page['messages'] ?? []), 'is_array'));
            if ($older === []) {
                // A server that pages nothing older: stop where we are.
                $this->snapshot['hasMoreOlder'] = false;

                return \React\Promise\resolve($this->completeSnapshot());
            }
            $this->snapshot['messages'] = \array_merge($older, (array) ($this->snapshot['messages'] ?? []));
            $this->snapshot['totalMessages'] = (int) ($page['totalMessages'] ?? $this->snapshot['totalMessages'] ?? \count((array) $this->snapshot['messages']));
            $this->snapshot['oldestIndex'] = (int) ($page['oldestIndex'] ?? 0);
            $this->snapshot['hasMoreOlder'] = (bool) ($page['hasMoreOlder'] ?? false);

            return $this->pageOlder($pageRows, $pagesLeft - 1);
        });
    }

    /**
     * Once the walk has reached the first row, the paging meta has served its
     * purpose: drop it so a fully-stitched snapshot is the shape the client
     * saw before paging existed. A walk stopped at {@see MAX_SNAPSHOT_PAGES}
     * keeps `hasMoreOlder` — the window really is not the whole transcript.
     *
     * @return array<string, mixed>
     */
    private function completeSnapshot(): array
    {
        if (($this->snapshot['oldestIndex'] ?? 0) === 0 && ($this->snapshot['hasMoreOlder'] ?? false) === false) {
            unset($this->snapshot['totalMessages'], $this->snapshot['oldestIndex'], $this->snapshot['hasMoreOlder']);
        }

        return $this->snapshot;
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
            throw RpcError::notFound(Lang::t('host.remote.no_session', ['session' => $target]), 'session_not_found');
        }

        throw RpcError::of(ErrorCode::Conflict, Lang::t('host.remote.ambiguous', [
            'prefix' => $target,
            'count' => \count($prefixed),
            'ids' => \implode(', ', \array_map(static fn (array $row): string => (string) $row['id'], $prefixed)),
        ]), 'ambiguous');
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

    /**
     * The cached `command.list` roster (C-SES-7.f): command name to "server"
     * or "client". Empty until {@see open()}'s fetch answers — and it answers
     * exactly once, so callers treat an absent name as "run it here", never
     * as "the server refused".
     *
     * @return array<string, string>
     */
    public function serverCommands(): array
    {
        return $this->serverCommands;
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

    /** A dropped frame is otherwise invisible; say so once per stream, not per frame. */
    private function noteMalformed(string $what): void
    {
        if ($this->malformedLogged) {
            return;
        }
        $this->malformedLogged = true;
        Log::channel('host', 'dropped malformed frame (further drops on this stream are not logged): ' . $what);
    }

    private function receive(string $payload): void
    {
        try {
            $message = \json_decode($payload, true, JsonRpc::MAX_DEPTH, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->noteMalformed('undecodable frame: ' . \SugarCraft\Code\Server\ErrorLog::flatten($e->getMessage()));

            return;
        }
        if (!\is_array($message)) {
            $this->noteMalformed('frame is not a JSON object');

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
                } catch (\Throwable $e) {
                    // One listener's failure is its own; the others still hear.
                    Log::channel('host', 'event listener threw: ' . \SugarCraft\Code\Server\ErrorLog::line($e, 'listener'));
                }
            }

            return;
        }

        $id = $message['id'] ?? null;
        if (!\is_string($id) || !$this->pending->has($id)) {
            return;
        }
        $preserve = $this->pending->isPreserving($id);

        if (\array_key_exists('result', $message)) {
            if ($preserve) {
                $objects = \json_decode($payload, false, JsonRpc::MAX_DEPTH);
                $this->pending->settle($id, \is_object($objects) && \property_exists($objects, 'result') ? $objects->result : new \stdClass());

                return;
            }
            $this->pending->settle($id, \is_array($message['result']) ? $message['result'] : []);

            return;
        }

        $error = \is_array($message['error'] ?? null) ? $message['error'] : [];
        $data = \is_array($error['data'] ?? null) ? $error['data'] : [];
        $kind = \is_string($data['kind'] ?? null) ? $data['kind'] : null;
        unset($data['kind']);
        $this->pending->fail($id, RpcError::of(
            ErrorCode::tryFrom((int) ($error['code'] ?? 0)) ?? ErrorCode::Internal,
            \is_string($error['message'] ?? null) ? $error['message'] : Lang::t('host.remote.refused'),
            $kind,
            $data,
        ));
    }

    /**
     * The table's settle callback for one Deferred: a failure rejects it, a
     * result resolves it.
     *
     * @return \Closure(?\Throwable, mixed): void
     */
    private static function settling(Deferred $deferred): \Closure
    {
        return static function (?\Throwable $failure, mixed $result) use ($deferred): void {
            $failure !== null ? $deferred->reject($failure) : $deferred->resolve($result);
        };
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
                $this->dropped(Lang::t('host.remote.server_closed', ['code' => $code, 'reason' => $reason !== '' ? ': ' . $reason : '']));
                break;
        }
    }

    /**
     * The socket died (ISS-114). With a session to return to and a way to
     * re-dial, a drop is a pause: the requests the lost socket could not
     * answer are rejected exactly as before, but the listeners, the snapshot
     * and the cursor survive, and the host runs its `server.hello` resume
     * ladder. Without one of those the drop is final, as it always was:
     * {@see gone()}. Exactly one ladder runs at a time — a resume socket's own
     * death rejects the hello it was answering, and that rejection is the
     * attempt's one failure, never a fresh drop.
     */
    private function dropped(string $reason): void
    {
        if ($this->closing) {
            $this->gone($reason);

            return;
        }
        if ($this->reconnecting) {
            if ($this->open && $this->deadEpoch !== $this->epoch) {
                // A resume socket died mid-attempt. Its hello rejects with this
                // reason, and that rejection is the attempt's failure, counted once
                // by the ladder: the close frame and the stream's own close are one verdict.
                $this->deadEpoch = $this->epoch;
                $this->open = false;
                $this->closeReason = $reason;
                $this->rejectPending($reason);
            }

            return;
        }
        if (!$this->open) {
            return;
        }
        if ($this->reopen === null || $this->sessionId === null || $this->hello === null) {
            $this->gone($reason);

            return;
        }
        $this->open = false;
        $this->deadEpoch = $this->epoch;
        $this->closeReason = $reason;
        $this->rejectPending($reason);
        $this->reconnecting = true;
        $this->attempt = 0;
        $this->dropReason = $reason;
        $this->scheduleLadder();
    }

    /** Wait out the first backoff, then start the redial ladder. */
    private function scheduleLadder(): void
    {
        $after = $this->after ?? static function (float $seconds, \Closure $run): void {
            Loop::addTimer($seconds, static function () use ($run): void {
                $run();
            });
        };
        $after(self::RECONNECT_BASE_DELAY_SECONDS, function (): void {
            try {
                $this->startLadder();
            } catch (\Throwable $e) {
                // Runs from a Loop timer: a throw here would unwind Loop::run()
                // and strand the attachment half-open. End it instead.
                $this->gone($this->dropReason . ' ' . \SugarCraft\Code\Server\ErrorLog::flatten($e->getMessage()), true);
            }
        });
    }

    /**
     * Redial through sugar-async's reconnect. Each attempt is one dial plus the
     * resume hello it leads to, so a refused dial, a throwing dial and a failed
     * hello each cost one of RECONNECT_MAX_ATTEMPTS. A resume the server refuses
     * outright is not an attempt: {@see resume()} ends the attachment there.
     * Closing cancels the cycle through its token; the cancelled rejection is
     * not a verdict, because close() has already given one.
     */
    private function startLadder(): void
    {
        if (!$this->reconnecting || $this->closing || $this->reopen === null) {
            return;
        }
        $cancel = new CancellationSource();
        $this->cancelLadder = $cancel;
        AsyncOps::reconnect(
            fn (): PromiseInterface => $this->redial(),
            $this->redialBackoff ?? self::redialLadder(),
            $cancel->token(),
            null,
            function (int $failedAttempt): void {
                $this->attempt = $failedAttempt;
            },
        )->then(
            null,
            function (\Throwable $failure): void {
                if ($this->closing || !$this->reconnecting || $failure instanceof OperationCancelledException) {
                    return;
                }
                $this->gone($this->dropReason . ' ' . Lang::t('host.remote.reconnect_exhausted', ['attempts' => self::RECONNECT_MAX_ATTEMPTS]), true);
            },
        );
    }

    /**
     * The redial ladder behind {@see startLadder()}. The first redial is
     * immediate (the first rung already waited), and each failed attempt then
     * waits one rung more: 0.5, 1, 2, 4 ... seconds, capped, for
     * RECONNECT_MAX_ATTEMPTS attempts in all.
     */
    private static function redialLadder(): Backoff
    {
        return Backoff::new(
            self::RECONNECT_BASE_DELAY_SECONDS * 2000.0,
            2.0,
            (int) (self::RECONNECT_MAX_DELAY_SECONDS * 1000),
        )->withAttempts(self::RECONNECT_MAX_ATTEMPTS);
    }

    /**
     * One redial attempt: dial the endpoint, then resume on the new socket. The
     * promise settles with the resume's own answer, so a failed hello fails the
     * attempt. A socket that lands after close() is given back at once.
     *
     * @return PromiseInterface<null>
     */
    private function redial(): PromiseInterface
    {
        $redial = $this->reopen ?? throw new \LogicException('a redial needs a reopen');

        return $redial()->then(function (array $socket): PromiseInterface {
            if ($this->closing || !$this->reconnecting) {
                $socket[0]->close();

                return reject(new OperationCancelledException('Reconnect cancelled'));
            }

            return $this->resume($socket);
        });
    }

    /**
     * Speak again on a fresh socket: re-hello with `resume` from the cursor,
     * so the server re-subscribes this client and replays what it missed —
     * a running turn keeps streaming here instead of being mourned as lost.
     * A session the server can no longer serve from the cursor answers with a
     * reset snapshot, paged and stitched like {@see open()} does; a session it
     * refuses outright (gone, held elsewhere) ends the attachment, because no
     * redial changes that verdict.
     *
     * @param array{0: DuplexStreamInterface, 1: string} $socket
     * @return PromiseInterface<null> rejects with the hello's failure, or with a timeout when it goes unanswered, which the ladder counts
     */
    private function resume(array $socket): PromiseInterface
    {
        [$stream, $initial] = $socket;
        $this->open = true;
        $this->closeReason = null;
        $this->wire($stream, $initial);

        // A hello that never answers fails the attempt: the timeout's rejection
        // runs the same abandon() below as a refused hello, freeing the socket.
        // The deadline grows by one base per failed attempt, so a healthy server
        // slower than the base is reached on a later redial rather than failing
        // every attempt the same way; a silent one still ends in the ladder.
        $deadline = $this->resumeHelloSeconds * ($this->attempt + 1);

        return AsyncOps::withTimeout($this->call('server.hello', $this->helloParams([
            'resume' => [(string) $this->sessionId => $this->lastSeq],
            'snapshotLimit' => $this->snapshotPageRows,
        ])), $deadline, null, 'server.hello')->then(function (array $result): void {
            if (!$this->open) {
                // This socket died while its hello was being answered: the attempt fails with it.
                throw new \RuntimeException($this->closeReason ?? 'the resumed socket closed');
            }
            $this->hello = $result;
            $entry = (array) ($result['resumed'][(string) $this->sessionId] ?? []);
            if ($entry === [] || ($entry['error'] ?? null) !== null) {
                $this->gone($this->dropReason . ' ' . Lang::t('host.remote.resume_refused', [
                    'reason' => (string) ($entry['error'] ?? 'the session was not resumed'),
                ]));

                return;
            }
            if (isset($entry['snapshot'])) {
                $this->adoptSnapshot((array) $entry['snapshot'], (int) ($entry['throughSeq'] ?? 0));
                // A failed stitch leaves the newest page, not the turn:
                // events keep flowing from the reset cursor regardless.
                $this->pageOlder($this->snapshotPageRows)->then(null, static function (): void {
                });
            } elseif (isset($entry['fromSeq'])) {
                $this->lastSeq = \max($this->lastSeq, (int) ($entry['throughSeq'] ?? $this->lastSeq));
            }
            $this->reconnecting = false;
            $this->attempt = 0;
        })->then(null, function (\Throwable $failure) use ($stream): void {
            // A refused hello or a socket that died mid-hello: the attempt is over
            // and its socket is the host's no longer.
            $this->abandon($stream, $failure);

            throw $failure;
        });
    }

    /**
     * Close the socket a failed resume opened, before the ladder redials. It is
     * marked dead first, so its close event is silenced and it cannot count as a
     * second verdict. The pause that follows carries the failure as its reason,
     * as dropped() does; a close that already ended the attachment keeps its own.
     * Calls issued while the hello was in flight went out on this socket and
     * will never be answered, so the failure settles them, as dropped() does.
     */
    private function abandon(DuplexStreamInterface $stream, \Throwable $failure): void
    {
        $current = $stream === $this->stream;
        if ($current) {
            if ($this->open) {
                $this->closeReason = $failure->getMessage() !== '' ? $failure->getMessage() : $this->dropReason;
            }
            $this->deadEpoch = $this->epoch;
            $this->open = false;
        }
        $stream->close();
        if ($current) {
            $this->rejectPending($failure->getMessage() !== '' ? $failure->getMessage() : $this->dropReason);
        }
    }

    /** Requests the current socket cannot answer any more, with why. */
    private function rejectPending(string $reason): void
    {
        $this->pending->failAll($reason);
    }

    /**
     * The final end: listeners hear why, nothing more will be tried. $force
     * delivers the verdict from a pause too — exhaustion or a user close
     * during backoff, where the socket is already down but the listeners
     * still wait for the decision.
     */
    private function gone(string $reason, bool $force = false): void
    {
        $this->reconnecting = false;
        if (!$this->open && !$force) {
            return;
        }
        $this->open = false;
        $this->closeReason = $reason;
        $this->rejectPending($reason);

        $listeners = $this->closeListeners;
        $this->closeListeners = [];
        foreach ($listeners as $listener) {
            try {
                $listener($reason);
            } catch (\Throwable $e) {
                Log::channel('host', 'close listener threw: ' . \SugarCraft\Code\Server\ErrorLog::line($e, 'listener'));
            }
        }
        $this->stream->close();
    }
}

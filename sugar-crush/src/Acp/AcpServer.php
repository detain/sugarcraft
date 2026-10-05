<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Acp;

use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use SugarCraft\Crush\Host\SessionEvent;
use SugarCraft\Crush\Host\SessionHub;
use SugarCraft\Crush\Host\TurnTicket;
use SugarCraft\Crush\McpMessage;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Protocol\ErrorCode;
use SugarCraft\Crush\Protocol\RpcError;
use SugarCraft\Crush\Role;

/**
 * The Agent Client Protocol agent side: newline-delimited JSON-RPC 2.0 from an
 * editor (Zed, JetBrains, Neovim) in, `session/update` notifications and
 * answers out, every session driven through a {@see SessionHub}'s
 * {@see \SugarCraft\Crush\Host\SessionHost} (roadmap 5.9, Appendix O §6.12).
 *
 * TRANSPORT-FREE. A line in is {@see receive()}, a line out is the $write
 * closure, the host's tick is {@see tick()}; `Cli\Acp` wires those to stdin,
 * stdout and a loop timer, and a test wires them to arrays.
 *
 * THE METHODS (client → agent): `initialize`, `authenticate` (nothing to
 * authenticate — the editor started this process as its own user),
 * `session/new`, `session/prompt` and the `session/cancel` notification. The
 * agent → client traffic is `session/update` (message and thought chunks,
 * tool calls and their updates, the todo plan) and the
 * `session/request_permission` request ({@see AcpPermissionBridge}). The
 * editor's `fs/*` and `terminal/*` are not used: sugar-crush keeps its own
 * tools, its own permission gate and its own checkpoints.
 *
 * IDS ARE ECHOED EXACTLY (decision D12): every message is read with
 * {@see McpMessage::parsePreservingId()}, so a request sent with the integer
 * id `7` is answered with `7`, never `"7"`.
 *
 * NOTHING BLOCKS. A prompt starts a turn and returns; its answer is sent from
 * {@see tick()} once the session is idle. A permission question goes out as a
 * request and its answer is applied whenever it arrives, while every other
 * message is still read — there is no reply to wait for in a loop.
 *
 * ONE PROCESS, ONE PROJECT ROOT. The workspace is built for the `cwd` of the
 * first `session/new` (as `serve` builds one for its root); a later session
 * naming another directory is refused rather than run in the wrong project.
 *
 * MUTABLE ON PURPOSE: the live state of one editor connection.
 */
final class AcpServer
{
    /** The ACP protocol version this agent speaks. */
    public const PROTOCOL_VERSION = 1;

    /** How often {@see start()} ticks the open sessions (the server's rate). */
    public const TICK_SECONDS = 0.05;

    /** JSON-RPC 2.0's own "internal error", for a failure nothing else names. */
    public const INTERNAL_ERROR = -32603;

    public const METHOD_INITIALIZE = 'initialize';
    public const METHOD_AUTHENTICATE = 'authenticate';
    public const METHOD_SESSION_NEW = 'session/new';
    public const METHOD_SESSION_PROMPT = 'session/prompt';
    public const METHOD_SESSION_CANCEL = 'session/cancel';
    public const METHOD_SESSION_UPDATE = 'session/update';

    /** The marker a handler returns for a request it answers later. */
    private const DEFERRED = "\0deferred";

    private ?SessionHub $hub = null;

    private ?string $root = null;

    private AcpUpdateMapper $mapper;

    private bool $initialized = false;

    /** @var array<string, AcpSession> */
    private array $sessions = [];

    /** @var array<int, array{sessionId: string, askId: string}> open permission requests, by our id */
    private array $asking = [];

    private int $nextId = 0;

    private ?TimerInterface $timer = null;

    private ?LoopInterface $loop = null;

    /**
     * @param \Closure(string): SessionHub $hubFor the hub for a project root
     * @param \Closure(string): void $write one line out, without its newline
     */
    private function __construct(
        private readonly \Closure $hubFor,
        private readonly \Closure $write,
        private readonly string $version,
    ) {
        $this->mapper = AcpUpdateMapper::new();
    }

    /**
     * @param \Closure(string): SessionHub $hubFor builds the workspace's hub for
     *        the first session's `cwd` (the project root)
     * @param \Closure(string): void $write sends one JSON line to the client
     */
    public static function new(\Closure $hubFor, \Closure $write, string $version = 'dev'): self
    {
        return new self($hubFor, $write, $version);
    }

    /** Tick the sessions on $loop until {@see stop()}. */
    public function start(LoopInterface $loop): void
    {
        $this->loop = $loop;
        $this->timer ??= $loop->addPeriodicTimer(self::TICK_SECONDS, fn () => $this->tick());
    }

    /** Stop ticking, stop listening, and close every session (cancelling its turn). */
    public function stop(): void
    {
        if ($this->timer !== null) {
            $this->loop?->cancelTimer($this->timer);
            $this->timer = null;
        }
        foreach ($this->sessions as $session) {
            $session->detach();
        }
        $this->sessions = [];
        $this->asking = [];
        $this->hub?->closeAll();
    }

    /** The hub sessions run on, once the first `session/new` built it. */
    public function hub(): ?SessionHub
    {
        return $this->hub;
    }

    /** @return list<string> the sessions this connection opened */
    public function sessionIds(): array
    {
        return array_map(static fn (int|string $id): string => (string) $id, array_keys($this->sessions));
    }

    /** One line from the client. */
    public function receive(string $line): void
    {
        $line = trim($line);
        if ($line === '') {
            return;
        }

        $message = McpMessage::parsePreservingId($line);
        if ($message === null) {
            $this->sendError(null, json_validate($line)
                ? RpcError::of(ErrorCode::InvalidRequest, 'invalid request: expected a JSON-RPC 2.0 message')
                : RpcError::of(ErrorCode::ParseError, 'parse error'));

            return;
        }

        if ($message->isResponse()) {
            $this->answered($message);

            return;
        }

        $method = (string) $message->method;
        $params = $message->params ?? [];
        if ($message->isNotification()) {
            $this->notified($method, $params);

            return;
        }

        $id = $message->wireId;
        try {
            $result = $this->call($method, $params, $id);
        } catch (RpcError $e) {
            $this->sendError($id, $e);

            return;
        } catch (\Throwable $e) {
            $this->send(McpMessage::error('', self::INTERNAL_ERROR, $e->getMessage())->withWireId($id));

            return;
        }

        if ($result !== self::DEFERRED) {
            $this->sendResult($id, $result);
        }
    }

    /**
     * The host's tick: fold every running turn's live events, and answer each
     * prompt whose session has gone idle.
     */
    public function tick(): void
    {
        foreach ($this->sessions as $session) {
            if ($session->host->isBusy()) {
                $session->host->pump();
            }
            $this->settle($session);
        }
    }

    // ── client → agent ─────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $params
     */
    private function call(string $method, array $params, string|int|null $id): mixed
    {
        if ($method !== self::METHOD_INITIALIZE && !$this->initialized) {
            throw RpcError::of(ErrorCode::NotInitialized, 'call initialize first');
        }

        return match ($method) {
            self::METHOD_INITIALIZE => $this->initialize($params),
            self::METHOD_AUTHENTICATE => new \stdClass(),
            self::METHOD_SESSION_NEW => $this->newSession($params),
            self::METHOD_SESSION_PROMPT => $this->prompt($params, $id),
            default => throw RpcError::of(ErrorCode::MethodNotFound, \sprintf('method not found: %s', $method)),
        };
    }

    /** @param array<string, mixed> $params */
    private function notified(string $method, array $params): void
    {
        if ($method !== self::METHOD_SESSION_CANCEL) {
            return;
        }
        $session = $this->sessions[(string) ($params['sessionId'] ?? '')] ?? null;
        // The MVP's cancel (roadmap 5.9-1): the turn stops at its next step
        // boundary and the prompt is answered when it does.
        $session?->host->cancelSoft();
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function initialize(array $params): array
    {
        $this->initialized = true;

        return [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'agentCapabilities' => [
                'loadSession' => false,
                'promptCapabilities' => ['image' => false, 'audio' => false, 'embeddedContext' => true],
                'mcpCapabilities' => ['http' => false, 'sse' => false],
            ],
            'authMethods' => [],
            'agentInfo' => ['name' => 'sugarcrush', 'title' => 'SugarCrush', 'version' => $this->version],
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function newSession(array $params): array
    {
        $hub = $this->hubFor(self::cwd($params));
        $session = $this->adopt($hub->create());

        return ['sessionId' => $session->sessionId()];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function prompt(array $params, string|int|null $id): mixed
    {
        $session = $this->session($params);
        if ($session->isPrompting() || $session->host->isBusy()) {
            throw RpcError::of(ErrorCode::Busy, 'this session is already running a prompt');
        }
        $blocks = $params['prompt'] ?? null;
        if (!\is_array($blocks) || !array_is_list($blocks)) {
            throw RpcError::invalidParams('prompt must be a list of content blocks');
        }
        $text = $this->promptText($blocks);
        if (trim($text) === '') {
            throw RpcError::invalidParams('the prompt has no text');
        }

        $before = \count($session->host->history());
        $session->beginPrompt($id);
        try {
            $ticket = $session->host->submit($text);
        } catch (\Throwable $e) {
            $session->endPrompt();

            throw $e;
        }

        if ($ticket->admitted === TurnTicket::REFUSED) {
            $session->endPrompt();
            $this->update($session, AcpUpdateMapper::messageChunk((string) $ticket->reason));

            return ['stopReason' => StopReasonMap::REFUSAL];
        }

        if ($ticket->admitted === TurnTicket::HANDLED) {
            // A slash command: its output is rows, already in the transcript.
            foreach (\array_slice($session->host->history(), $before) as $row) {
                if ($row->role !== Role::User) {
                    $this->replay($session, $row);
                }
            }
        }

        // A turn that settled inside submit() (or a command that ran none) is
        // answered now; otherwise tick() answers it when the session idles.
        $this->settle($session);

        return self::DEFERRED;
    }

    /**
     * The editor's answer to a permission question we asked.
     */
    private function answered(McpMessage $message): void
    {
        $id = $message->wireId;
        if (!\is_int($id) || !isset($this->asking[$id])) {
            return;
        }
        ['sessionId' => $sessionId, 'askId' => $askId] = $this->asking[$id];
        unset($this->asking[$id]);

        $session = $this->sessions[$sessionId] ?? null;
        if ($session === null) {
            return;
        }
        $result = $message->isError() ? null : $message->result;
        $session->host->answer(
            $askId,
            AcpPermissionBridge::reply($result),
            AcpPermissionBridge::isCancelled($result) ? 'the editor cancelled the prompt' : null,
        );
    }

    // ── host → client ──────────────────────────────────────────────────

    /** One event of $session's, as the editor hears it. */
    private function heard(AcpSession $session, SessionEvent $event): void
    {
        $data = $event->data;
        switch ($event->type) {
            case SessionEvent::TURN_STARTED:
                $session->beginTurn();

                return;

            case SessionEvent::ASSISTANT_DELTA:
                $session->streamedText((string) ($data['text'] ?? ''));
                break;

            case SessionEvent::REASONING_DELTA:
                $session->streamedThought((string) ($data['text'] ?? ''));
                break;

            case SessionEvent::ASSISTANT_COMPLETED:
                $thought = $session->unsentThought(\is_string($data['reasoning'] ?? null) ? $data['reasoning'] : '');
                if ($thought !== '') {
                    $this->update($session, AcpUpdateMapper::thoughtChunk($thought));
                }
                $text = $session->unsentText(\is_string($data['content'] ?? null) ? $data['content'] : '');
                if ($text !== '') {
                    $this->update($session, AcpUpdateMapper::messageChunk($text));
                }

                return;

            case SessionEvent::TURN_COMPLETED:
                $session->completed(
                    \is_string($data['stopReason'] ?? null) ? $data['stopReason'] : null,
                    \is_string($data['error'] ?? null) ? $data['error'] : null,
                );

                return;

            case SessionEvent::PERMISSION_REQUESTED:
                $this->ask($session, $data);

                return;
        }

        foreach ($this->mapper->fromEvent($event) as $update) {
            $this->update($session, $update);
        }
    }

    /**
     * Put a turn's permission question to the editor.
     *
     * @param array<string, mixed> $data
     */
    private function ask(AcpSession $session, array $data): void
    {
        $askId = $data['askId'] ?? null;
        if (!\is_string($askId) || $askId === '') {
            return;
        }
        $id = ++$this->nextId;
        $this->asking[$id] = ['sessionId' => $session->sessionId(), 'askId' => $askId];
        $this->send(McpMessage::request((string) $id, AcpPermissionBridge::METHOD, self::scrub(AcpPermissionBridge::request($session->sessionId(), $data, $this->mapper)))->withWireId($id));
    }

    /** Answer $session's prompt once it has no turn left. */
    private function settle(AcpSession $session): void
    {
        if (!$session->isPrompting() || $session->host->isBusy()) {
            return;
        }
        $id = $session->promptId();
        [$stopReason, $error] = $session->endPrompt();
        if (StopReasonMap::isError($stopReason)) {
            $this->send(McpMessage::error('', self::INTERNAL_ERROR, $error ?? 'the turn failed')->withWireId($id));

            return;
        }

        $this->sendResult($id, ['stopReason' => StopReasonMap::toAcp($stopReason)]);
    }

    private function replay(AcpSession $session, Message $row): void
    {
        foreach ($this->mapper->fromRow($row) as $update) {
            $this->update($session, $update);
        }
    }

    /** @param array<string, mixed> $update */
    private function update(AcpSession $session, array $update): void
    {
        $this->send(McpMessage::notification(self::METHOD_SESSION_UPDATE, [
            'sessionId' => $session->sessionId(),
            'update' => self::scrub($update),
        ]));
    }

    // ── plumbing ───────────────────────────────────────────────────────

    /** Track $host's session: listen to it, and remember it by id. */
    private function adopt(\SugarCraft\Crush\Host\SessionHost $host): AcpSession
    {
        $session = AcpSession::new($host);
        $session->listening($host->onEvent(fn (SessionEvent $event) => $this->heard($session, $event)));
        $this->sessions[$session->sessionId()] = $session;

        return $session;
    }

    /** @param array<string, mixed> $params */
    private function session(array $params): AcpSession
    {
        $id = $params['sessionId'] ?? null;
        if (!\is_string($id) || $id === '') {
            throw RpcError::invalidParams('sessionId must be a non-empty string');
        }

        return $this->sessions[$id] ?? throw RpcError::notFound(\sprintf('no session %s on this connection', $id), 'session_not_found');
    }

    /** The hub for $cwd: built for the first, refused for any other root. */
    private function hubFor(string $cwd): SessionHub
    {
        if ($this->hub !== null) {
            if ($cwd !== $this->root) {
                throw RpcError::invalidParams(\sprintf(
                    'this agent serves the project at %s; start another sugarcrush acp for %s',
                    (string) $this->root,
                    $cwd,
                ));
            }

            return $this->hub;
        }

        $this->hub = ($this->hubFor)($cwd);
        $this->root = $cwd;
        $this->mapper = AcpUpdateMapper::new($cwd);

        return $this->hub;
    }

    /** @param array<string, mixed> $params */
    private static function cwd(array $params): string
    {
        $cwd = $params['cwd'] ?? null;
        if (!\is_string($cwd) || !str_starts_with($cwd, '/')) {
            throw RpcError::invalidParams('cwd must be an absolute path');
        }
        $real = realpath($cwd);
        if ($real === false || !is_dir($real)) {
            throw RpcError::invalidParams(\sprintf('cwd %s is not a directory', $cwd));
        }

        return $real;
    }

    /**
     * A prompt's content blocks as the text the session is sent: text as
     * written; a linked file as an `@path` mention, which the session
     * resolves and attaches as it does a typed one; an embedded resource as a `<file path>` block carrying the
     * editor's text — its unsaved buffer, which is why it is embedded. A
     * block this agent did not advertise (image, audio) is skipped.
     *
     * @param list<mixed> $blocks
     */
    private function promptText(array $blocks): string
    {
        $text = '';
        $embedded = [];
        foreach ($blocks as $block) {
            if (!\is_array($block)) {
                continue;
            }
            switch ($block['type'] ?? null) {
                case 'text':
                    $text .= \is_string($block['text'] ?? null) ? $block['text'] : '';
                    break;

                case 'resource_link':
                    $uri = \is_string($block['uri'] ?? null) ? $block['uri'] : '';
                    $text .= $uri === '' ? '' : self::mention($uri);
                    break;

                case 'resource':
                    $resource = \is_array($block['resource'] ?? null) ? $block['resource'] : [];
                    if (\is_string($resource['text'] ?? null)) {
                        $embedded[] = \sprintf(
                            "<file path=\"%s\">\n%s\n</file>",
                            htmlspecialchars(self::path((string) ($resource['uri'] ?? '')), \ENT_QUOTES),
                            $resource['text'],
                        );
                    }
                    break;
            }
        }

        return $embedded === [] ? $text : rtrim($text) . "\n\n" . implode("\n\n", $embedded);
    }

    /**
     * A `file://` link as the `@path` mention the session resolves — the
     * absolute path, which the resolver reads as it reads a typed one, quoted
     * when it holds whitespace. Any other URI stays text.
     */
    private static function mention(string $uri): string
    {
        if (!str_starts_with($uri, 'file://')) {
            return $uri;
        }
        $path = self::path($uri);

        return preg_match('/\s/', $path) === 1 ? '@"' . $path . '"' : '@' . $path;
    }

    /** The path a `file://` URI names; anything else as given. */
    private static function path(string $uri): string
    {
        return str_starts_with($uri, 'file://') ? rawurldecode(substr($uri, \strlen('file://'))) : $uri;
    }

    private function sendResult(string|int|null $id, mixed $result): void
    {
        $this->send(McpMessage::success('', $result === [] ? new \stdClass() : self::scrub($result))->withWireId($id));
    }

    private function sendError(string|int|null $id, RpcError $error): void
    {
        $member = $error->toArray();
        $this->send(McpMessage::error('', $member['code'], $member['message'], $member['data'])->withWireId($id));
    }

    /**
     * One message out. A message that cannot be encoded is not dropped
     * silently when someone waits on it: a request's answer becomes an
     * error answer instead (a lost answer is a prompt the editor waits on
     * forever); a notification nobody waits on is skipped.
     */
    private function send(McpMessage $message): void
    {
        try {
            $line = $message->toJson();
        } catch (\JsonException) {
            if ($message->isNotification() || $message->isRequest()) {
                return;
            }
            $line = McpMessage::error('', self::INTERNAL_ERROR, 'the answer could not be encoded as JSON')->withWireId($message->wireId)->toJson();
        }
        ($this->write)($line);
    }

    /**
     * $value with every string made valid UTF-8. Tool output and model text
     * are bytes this process does not control, and one invalid byte makes
     * the whole line unencodable.
     */
    private static function scrub(mixed $value): mixed
    {
        if (\is_string($value)) {
            return mb_scrub($value, 'UTF-8');
        }
        if (\is_array($value)) {
            return array_map(self::scrub(...), $value);
        }

        return $value;
    }
}

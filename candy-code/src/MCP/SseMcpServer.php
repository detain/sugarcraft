<?php

declare(strict_types=1);

namespace SugarCraft\Code\MCP;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use Psr\Http\Message\ResponseInterface;
use SugarCraft\McpClient\ArgumentShape;
use SugarCraft\McpClient\RequestIdSequence;

/**
 * Client half of the MCP **legacy HTTP+SSE transport** (protocol revision
 * 2024-11-05): the client opens ONE long-lived `GET` with
 * `Accept: text/event-stream`, the server's first act on it is an `endpoint`
 * event naming the URI that requests are POSTed to, and every JSON-RPC reply
 * — including the reply to the POST that carried the request — arrives on the
 * GET stream, matched by id. The POST response itself carries only a status:
 * delivery, not an answer.
 *
 * Audit MCP-4: before this class existed, a `.mcp.json` entry of
 * `"type": "sse"` threw at the factory's default arm. The transport is
 * deprecated in the current specification but every pre-2025-03 server speaks
 * ONLY it — this is the compat shim those operators need. Streamable HTTP is
 * the arm to prefer for any server that speaks both.
 *
 * THE ORIGIN LAW (the security line of this class): the `endpoint` event is
 * server-authored, and it controls where every later POST goes — an
 * attacker-influenced stream could otherwise steer requests, bearer and all,
 * to another host. The resolved endpoint MUST share the configured URL's
 * origin (scheme, host, port); anything else is refused loudly and never
 * contacted. The bearer never leaves the configured origin.
 *
 * WHY A RAW SOCKET FOR THE GET AND GUZZLE FOR THE POSTS. The GET is a
 * persistent stream whose reads must be deadline-bounded per wait; Guzzle's
 * streaming path is measured hostile territory for exactly this shape —
 * {@see HttpMcpServer::send()} records that streamed bodies served through
 * the wrapper block reads until the buffer fills or the socket closes, and
 * the shared client carries a fixed 30 s TOTAL timeout that would kill an
 * idle-but-healthy stream from its first quiet second. So the stream side is
 * a plain stream-socket client (TLS via php streams, `verify_peer` and SNI
 * on, no redirect following) with every read bounded by an explicit
 * deadline, and the POST side — short, request-shaped, header-rich — rides
 * the same Guzzle client (and therefore the same OAuth bearer precedence) as
 * the http transport. Chunked bodies are decoded here because the
 * specification requires its servers to support HTTP/1.1 chunked transfer.
 *
 * FORK SAFETY follows {@see HttpMcpServer}: ids are pid-tagged outside the
 * owner ({@see RequestIdSequence}), and POSTs go on fresh connections. The
 * GET cannot cross the fork at all — a reply posted in a child would land on
 * whichever stream the server associates with that request, not on the
 * parent's inherited socket the child must never read. So the FIRST call a
 * forked child makes establishes that child's own session (its own GET, its
 * own `initialize`) before POSTing; the parent's stream is never read from,
 * and closing the child's duplicated fd cannot disturb the parent's
 * independent descriptor.
 *
 * A DROPPED STREAM is a dead session in flight: the interrupted call fails
 * (never resent — the server may already be running the work), and recovery
 * is a BOUNDED backoff burst of {@see RECONNECT_BUDGET} attempts. A burst
 * that exhausts marks the session dropped and refuses; the next operator- or
 * model-driven call tries one fresh burst. Calls are user-paced, so a burst
 * per call is the bound — this class never loops on its own clock.
 */
final class SseMcpServer implements McpServer, McpResourceProvider, McpPromptProvider
{
    use WalksProviderPages;
    /** The revision this transport exists for; nothing else speaks `endpoint`. */
    public const PROTOCOL_VERSION = '2024-11-05';

    /**
     * ISS-042 family: the per-`tools/call` ceiling on the REPLY WAIT — the
     * same 120 s the stdio and http arms answer with, resolved by
     * {@see McpClient::buildServer()} from the entry's `toolTimeout`. E646
     * keeps it a per-call IO ceiling: it bounds waiting for one reply, never
     * a blanket timeout across the session, and the persistent GET is
     * deliberately UNTIMEOUTED between exchanges (a stream that goes quiet
     * for ten minutes between calls is healthy, and no wall-clock kill
     * touches it).
     */
    public const DEFAULT_TOOL_TIMEOUT_SECONDS = StdioMcpServer::DEFAULT_TOOL_TIMEOUT_SECONDS;

    /** Bound for the TCP+TLS connect. A dead host must cost seconds. */
    private const CONNECT_TIMEOUT_SECONDS = 10.0;

    /** Bound for the response-head wait after the request is written. */
    private const OPEN_TIMEOUT_SECONDS = 10.0;

    /** Bound for the server's `endpoint` event after the stream opens. */
    private const ENDPOINT_TIMEOUT_SECONDS = 10.0;

    /** Bound for POST DELIVERY — the answer is not a POST response, so this
     *  is not a work timeout, only a "the socket must stop being idle" bound. */
    private const POST_TIMEOUT_SECONDS = 10.0;

    /** Budget for delivering `notifications/cancelled` after an abandon. */
    private const CANCEL_NOTICE_TIMEOUT_SECONDS = 2.0;

    /** Page ceiling for the `tools/list` cursor walk (ISS-093(b) family). */
    private const MAX_TOOL_PAGES = 32;

    /** Reconnect attempts per drop burst before refusing (MCP-4 law). */
    private const RECONNECT_BUDGET = 3;

    /** First backoff, doubling per attempt, capped — a restart is seconds, not minutes. */
    private const RECONNECT_BACKOFF_SECONDS = 0.5;
    private const RECONNECT_BACKOFF_CAP_SECONDS = 4.0;

    /** Hostile-input bounds: a response head or one reply-wait of stream may not grow unbounded. */
    private const MAX_HEAD_BYTES = 65536;
    private const MAX_LINE_BYTES = 8192;
    private const MAX_STREAM_BYTES_PER_WAIT = 8388608;

    /** Headers the transport owns. Same drop-list as {@see HttpMcpServer}: a
     *  legacy server reads none of these from config, and a hand-set session
     *  id or protocol version is a 2025 concept this wire has no slot for. */
    private const TRANSPORT_OWNED_HEADERS = ['accept', 'content-type', 'mcp-session-id', 'mcp-protocol-version'];

    /** @var array<McpTool> */
    private array $tools = [];

    private bool $initialized = false;

    /** True once a drop burst refused: calls fail fast with the law's wording
     *  until a fresh burst (attempted by the next call) restores the session. */
    private bool $streamLost = false;

    private readonly RequestIdSequence $ids;

    /** @var resource|null the persistent GET socket, this process's own */
    private $socket = null;

    private ?SseLineParser $parser = null;

    /** The origin-locked POST target published by the server's endpoint event. */
    private ?string $postTarget = null;

    /** Wait bookkeeping: the reader bounds every read by this deadline and
     *  names this label in the refusal it raises when the deadline passes. */
    private ?float $waitDeadlineAt = null;

    private string $waitLabel = '';

    /** Bytes handed to the parser since the current wait began (hostile-flood bound). */
    private int $fedBytesInWait = 0;

    /** Chunked-decode state (transfer-encoding: chunked is what real servers send). */
    private bool $chunked = false;

    private string $payloadBuf = '';

    private ?int $chunkRemaining = null;

    private bool $lastChunkSeen = false;

    /**
     * @param \Closure|null $pidProvider @internal test seam returning the
     *        current pid (default getmypid()), same shape as the http arm's
     * @param \Closure|null $connector @internal test seam
     *        `fn(string $scheme, string $host, int $port, float $timeoutSeconds): resource`
     *        — replaces the socket connect only; the HTTP request/response,
     *        origin lock, framing and waits run for real over whatever socket
     *        the connector hands back
     * @param \Closure|null $sleeper @internal test seam `fn(float $seconds): void`
     *        — receives the reconnect backoff schedule so a drop test can pin
     *        the bounded schedule without living through it
     */
    public function __construct(
        public readonly string $name,
        private string $url,
        private array $headers,
        private Client $httpClient,
        private readonly ?McpAuthStore $authStore = null,
        ?\Closure $pidProvider = null,
        private readonly float $toolTimeoutSeconds = self::DEFAULT_TOOL_TIMEOUT_SECONDS,
        ?\Closure $connector = null,
        ?\Closure $sleeper = null,
    ) {
        $this->pidProvider = $pidProvider ?? static fn (): int => (int) getmypid();
        $this->ids = new RequestIdSequence($pidProvider);
        $this->connector = $connector ?? self::connectSocket(...);
        $this->sleeper = $sleeper ?? static function (float $seconds): void {
            usleep((int) round($seconds * 1000000));
        };
    }

    /** @var \Closure(): int */
    private readonly \Closure $pidProvider;

    /** @var \Closure(string, string, int, float): resource */
    private readonly \Closure $connector;

    /** @var \Closure(float): void */
    private readonly \Closure $sleeper;

    /** Pid of the process that opened the CURRENT stream. A forked child
     *  inherits the parent's session — including this number — and the
     *  mismatch is exactly what forces it to open its own before POSTing
     *  (see the FORK SAFETY note on the class). */
    private ?int $sessionPid = null;

    /** The endpoint this server was configured with. The auth notice prints
     *  exactly this URL because it is the key `mcp auth login` stores under —
     *  never the server-published POST target (see THE ORIGIN LAW). */
    public function endpoint(): string
    {
        return $this->url;
    }

    public function start(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->ids->claim();

        try {
            $this->establishSession();

            $this->tools = $this->collectToolPages($this->exchange('tools/list', null));
        } catch (HttpMcpAuthRequiredException $e) {
            $this->closeStream();

            throw $e;
        } catch (\Exception $e) {
            $this->closeStream();

            // THE CAUSE TRAVELS WITH THE WRAPPER (v4-F20): the message keeps
            // its shape for every existing pin, and the chain stays intact for
            // whoever logs $previous — a bare rethrow here silently severed the
            // stack that made the failure interesting.
            throw new \RuntimeException("Failed to start MCP server {$this->name}: {$e->getMessage()}", 0, $e);
        }

        $this->initialized = true;
    }

    public function stop(): void
    {
        $this->closeStream();
        $this->initialized = false;
        $this->streamLost = false;
    }

    /**
     * "Up" is the handshake-plus-stream pair: initialized since the last
     * {@see start()}, and no drop burst has refused since. A recovered stream
     * restores the answer without a fresh start.
     */
    public function isUp(): bool
    {
        return $this->initialized && !$this->streamLost;
    }

    /**
     * @return array<McpTool>
     */
    public function listTools(): array
    {
        return $this->tools;
    }

    /**
     * @return array<mixed>
     */
    public function callTool(string $toolName, array $args): array
    {
        try {
            $data = $this->exchange('tools/call', [
                'name' => $toolName,
                'arguments' => $args === [] ? new \stdClass() : ArgumentShape::conform($args, $this->inputSchemaOf($toolName)),
            ]);

            if ($data === null) {
                return ['error' => 'Invalid response'];
            }

            if (array_key_exists('error', $data)) {
                $error = $data['error'];

                return ['error' => is_string($error) ? $error : 'JSON-RPC error' . self::describeError($error)];
            }

            return $data['result'] ?? ['error' => 'Tool call failed'];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * @return array<array-key,mixed>
     */
    private function inputSchemaOf(string $toolName): array
    {
        foreach ($this->tools as $tool) {
            if ($tool->name === $toolName) {
                return $tool->inputSchema;
            }
        }

        return [];
    }

    /**
     * @return list<array<mixed>> raw `resources` rows — RAW BYTES LAW, see {@see McpResourceProvider}
     */
    public function listResources(): array
    {
        return $this->collectProviderPages('resources/list', 'resources');
    }

    /**
     * @return array<mixed>
     */
    public function readResource(string $uri): array
    {
        return $this->requireProviderResult('resources/read', $this->exchange('resources/read', ['uri' => $uri]));
    }

    /**
     * @return list<array<mixed>> raw `prompts` rows
     */
    public function listPrompts(): array
    {
        return $this->collectProviderPages('prompts/list', 'prompts');
    }

    /**
     * @param array<string,mixed> $arguments
     * @return array<mixed>
     */
    public function getPrompt(string $name, array $arguments = []): array
    {
        return $this->requireProviderResult(
            'prompts/get',
            $this->exchange('prompts/get', ['name' => $name, 'arguments' => $arguments === [] ? new \stdClass() : $arguments]),
        );
    }

    // ------------------------------------------------------------------
    // Session establishment
    // ------------------------------------------------------------------

    /**
     * Open the GET, take the `endpoint` event, run `initialize` and confirm
     * with `notifications/initialized`. Shared by {@see start()}, by drop
     * recovery, and by a forked child's first call — the three routes that
     * all need a LIVE, HANDSHAKEN session, on THIS process's own socket.
     */
    private function establishSession(): void
    {
        // Claimed BEFORE the legs run: handshake() exchanges through
        // exchange(), whose child-session guard compares this pid.
        $this->sessionPid = ($this->pidProvider)();

        $this->openStream();

        try {
            $this->resolvePostTarget($this->awaitEndpoint());
            $this->handshake();
        } catch (\Throwable $e) {
            $this->closeStream();

            throw $e;
        }
    }

    /**
     * The persistent GET: request written, response head read and gated
     * (2xx, auth status, `text/event-stream`), then the stream handed to the
     * shared SSE parser. NO overall timeout rides this connection — the
     * bound lives on each wait the server makes ({@see $waitDeadlineAt}).
     */
    private function openStream(): void
    {
        $parts = $this->parseUrl($this->url, 'configured URL');
        /** @var resource $socket */
        $socket = ($this->connector)($parts['scheme'], $parts['host'], $parts['port'], self::CONNECT_TIMEOUT_SECONDS);
        $this->streamLost = false;
        $this->postTarget = null;
        $this->payloadBuf = '';
        $this->chunkRemaining = null;
        $this->lastChunkSeen = false;
        $this->chunked = false;

        try {
            $target = ($parts['path'] ?? '') !== '' ? $parts['path'] : '/';
            if (isset($parts['query']) && $parts['query'] !== '') {
                $target .= '?' . $parts['query'];
            }

            $request = "GET {$target} HTTP/1.1\r\n"
                . 'Host: ' . $parts['host'] . ':' . $parts['port'] . "\r\n"
                . "Accept: " . self::PROTOCOL_STREAM_ACCEPT . "\r\n"
                . "Cache-Control: no-store\r\n"
                . "Connection: keep-alive\r\n";
            foreach ($this->filteredHeaders() as $header => $value) {
                $request .= "{$header}: {$value}\r\n";
            }
            $request .= "\r\n";

            $this->socket = $socket;
            $this->writeRequest($request);

            [$status, $reason, $head] = $this->readHead();
            $this->assertStreamHead($status, $reason, $head);

            $this->chunked = str_contains(strtolower($head['transfer-encoding'] ?? ''), 'chunked');
            $this->parser = new SseLineParser(function (): string {
                return $this->readStreamChunk();
            });
        } catch (\Throwable $e) {
            $this->closeSocket($socket);
            $this->socket = null;

            throw $e;
        }
    }

    private const PROTOCOL_STREAM_ACCEPT = 'text/event-stream';

    /**
     * Wait for the `endpoint` event and hand back the URI it names. The spec
     * makes it the stream's first event; any `message` traffic before it is
     * protocol-ignored, and any OTHER named event (including a second
     * `endpoint`) is skipped — the first one wins because a server republishing
     * the target mid-handshake is exactly the steering this method distrusts.
     */
    private function awaitEndpoint(): string
    {
        $parser = $this->parser;
        if ($parser === null) {
            throw new \RuntimeException('internal: endpoint awaited with no open stream');
        }

        $this->beginWait(self::ENDPOINT_TIMEOUT_SECONDS, 'the endpoint event');
        try {
            while (true) {
                $event = $parser->nextEvent();
                if ($event === null) {
                    throw new \RuntimeException('the event stream closed before its endpoint event');
                }
                if ($event['event'] === 'endpoint') {
                    $data = trim($event['data']);
                    if ($data === '') {
                        throw new \RuntimeException('the endpoint event carried no URI');
                    }

                    return $data;
                }
            }
        } catch (SseStreamDeadlineException $e) {
            throw new \RuntimeException('the event stream opened without an endpoint event: ' . $e->getMessage(), 0, $e);
        } finally {
            $this->endWait();
        }
    }

    /**
     * THE ORIGIN LAW, resolved in one place. The server-authored endpoint is
     * merged against the configured URL (absolute URIs pass through, path and
     * relative references resolve, as RFC 3986 asks of a base), and the FINAL
     * origin must equal the configured one — checked on the merged result
     * rather than per-shape, so every classification bug still lands on this
     * gate. A query-only reference is refused outright: it names no path, and
     * silently completing one would be guessing where the bearer goes.
     */
    private function resolvePostTarget(string $published): void
    {
        $base = $this->parseUrl($this->url, 'configured URL');

        if (preg_match('#^([a-zA-Z][a-zA-Z0-9+.-]*):#', $published) === 1) {
            $absolute = $this->parseUrl($published, 'endpoint event');
            if ($absolute['scheme'] !== 'http' && $absolute['scheme'] !== 'https') {
                throw $this->originRefusal($published);
            }
            $merged = $this->compose($absolute);
        } elseif (str_starts_with($published, '/')) {
            $merged = $base['scheme'] . '://' . $base['host'] . ':' . $base['port'] . $published;
            $parsed = $this->parseUrl($merged, 'endpoint event');
            $merged = $this->compose($parsed);
        } elseif (str_starts_with($published, '?') || str_starts_with($published, '#')) {
            throw new \RuntimeException(
                'MCP server ' . $this->name . ': the endpoint event named "' . $published
                . '" — a reference without a path; refusing to guess where requests POST',
            );
        } else {
            // RFC 3986 relative reference: resolved against the configured
            // URL's DIRECTORY (everything through the last "/"), which for
            // "/sse" is "/" — the path that published the stream contributes
            // its own file name to no one. Dot-segments are not folded; the
            // origin gate bounds any damage either way.
            $basePath = $base['path'] ?? '/';
            $slash = strrpos($basePath, '/');
            $dir = $slash === false ? '/' : substr($basePath, 0, $slash + 1);
            $merged = $base['scheme'] . '://' . $base['host'] . ':' . $base['port'] . $dir . $published;
            $parsed = $this->parseUrl($merged, 'endpoint event');
            $merged = $this->compose($parsed);
        }

        $mergedParts = $this->parseUrl($merged, 'endpoint event');
        if (
            $mergedParts['scheme'] !== $base['scheme']
            || $mergedParts['host'] !== $base['host']
            || $mergedParts['port'] !== $base['port']
        ) {
            throw $this->originRefusal($published);
        }

        $this->postTarget = $merged;
    }

    private function originRefusal(string $published): \RuntimeException
    {
        return new \RuntimeException(
            'MCP server ' . $this->name . ': the endpoint event points at a DIFFERENT ORIGIN than the'
            . ' configured URL ("' . $published . '" vs ' . $this->url . ') — refusing to POST there.'
            . ' The event stream decides the POST target, so a cross-origin endpoint would ship every'
            . ' request, bearer included, to a host the operator never configured.',
        );
    }

    /**
     * @return array{scheme: string, host: string, port: int, path?: string, query?: string}
     */
    private function parseUrl(string $url, string $what): array
    {
        $parts = parse_url($url);
        $scheme = is_array($parts) && isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : '';
        $host = is_array($parts) && isset($parts['host']) ? strtolower((string) $parts['host']) : '';

        if (($scheme !== 'http' && $scheme !== 'https') || $host === '') {
            throw new \RuntimeException(
                'MCP server ' . $this->name . ": {$what} \"{$url}\" is not an http(s) URL with a host",
            );
        }

        $port = is_array($parts) && isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);

        $out = ['scheme' => $scheme, 'host' => $host, 'port' => $port];
        if (is_array($parts) && isset($parts['path'])) {
            $out['path'] = (string) $parts['path'];
        }
        if (is_array($parts) && isset($parts['query'])) {
            $out['query'] = (string) $parts['query'];
        }

        return $out;
    }

    /**
     * @param array{scheme: string, host: string, port: int, path?: string, query?: string} $parts
     */
    private function compose(array $parts): string
    {
        $url = $parts['scheme'] . '://' . $parts['host'] . ':' . $parts['port'] . ($parts['path'] ?? '/');
        if (isset($parts['query']) && $parts['query'] !== '') {
            $url .= '?' . $parts['query'];
        }

        return $url;
    }

    /**
     * The lifecycle legs over the just-published target: `initialize` (the
     * reply rides the stream) then `notifications/initialized` (delivery only).
     */
    private function handshake(): void
    {
        $reply = $this->exchange('initialize', [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 'candy-code', 'version' => '1.0.0'],
        ]);

        if ($reply === null) {
            throw new \RuntimeException('initialize returned an invalid response');
        }

        if (isset($reply['error'])) {
            throw new \RuntimeException('initialize refused' . self::describeError($reply['error']));
        }

        if (!isset($reply['result']) || !is_array($reply['result'])) {
            throw new \RuntimeException('initialize returned no result');
        }

        $response = $this->sendPost(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
        $this->assertSuccess($response);
    }

    // ------------------------------------------------------------------
    // Exchange
    // ------------------------------------------------------------------

    /**
     * One POST + one stream wait. A forked child runs {@see establishSession()}
     * first — on its own socket, into its own session — because the reply it
     * awaits could never arrive on the parent's stream.
     *
     * @param array<string, mixed>|null $params
     * @return array<mixed>|null
     */
    private function exchange(string $method, ?array $params): ?array
    {
        // The dropped state is checked BEFORE the ownership guard, and the
        // order is load-bearing: a failed burst runs closeStream() (which
        // nulls the owning pid), so an ownership-first check would route the
        // next call into the child branch and surface a raw connect error
        // instead of the law's refusal wording.
        if ($this->streamLost) {
            if (!$this->recoverStream()) {
                throw new \RuntimeException(
                    'MCP server ' . $this->name . ': the event stream is dropped and all '
                    . self::RECONNECT_BUDGET . ' reconnect attempts failed — refusing to call '
                    . $method . ' until the session is started again',
                );
            }
        } elseif ($this->sessionPid !== ($this->pidProvider)()) {
            // First call in a process that does not own the inherited stream
            // (a forked child): this process must read its OWN socket, never
            // the parent's.
            $this->establishSession();
        }

        $id = $this->ids->next();
        $payload = ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method];
        if ($params !== null) {
            $payload['params'] = $params;
        }

        try {
            $response = $this->sendPost($payload);
            $this->assertSuccess($response);
        } catch (TransferException $e) {
            $this->sendCancelled($id, 'the client could not complete the request');

            throw new \RuntimeException(sprintf(
                'MCP call to %s was abandoned: %s (%s)',
                $method,
                'the request never reached the server',
                $e->getMessage(),
            ), 0, $e);
        }

        $parser = $this->parser;
        if ($parser === null) {
            throw new \RuntimeException('MCP server ' . $this->name . ': the session died between POST and reply wait');
        }

        $this->beginWait($this->toolTimeoutSeconds, "a response to request {$id}");
        try {
            $reply = $parser->replyTo($id);
        } catch (SseStreamDeadlineException) {
            $this->sendCancelled($id, 'the client abandoned the request at its configured timeout');

            throw new \RuntimeException(sprintf(
                'MCP call to %s was abandoned: %s',
                $method,
                sprintf('no reply within %s seconds on the event stream', rtrim(rtrim(number_format($this->toolTimeoutSeconds, 3, '.', ''), '0'), '.')),
            ));
        } finally {
            $this->endWait();
        }

        if ($reply === null) {
            // End of stream: the server (or a middlebox) closed the GET. The
            // request WAS delivered, so the cancellation goes out best-effort
            // before recovery — a dead target costs its 2 s and nothing more.
            $this->sendCancelled($id, 'the event stream closed before the reply arrived');
            $recovered = $this->recoverStream();
            if (!$recovered) {
                $this->streamLost = true;
            }

            throw new \RuntimeException(sprintf(
                'MCP call to %s was abandoned: the event stream closed before the reply%s',
                $method,
                $recovered ? ' (the session was re-established; the interrupted call is not resent — the server may already be working on it)' : '',
            ));
        }

        return $reply;
    }

    /**
     * A drop burst: up to {@see RECONNECT_BUDGET} fresh sessions with a
     * bounded doubling backoff between them. Success restores the stream —
     * the tool roster is NOT re-collected, the launch freeze being a
     * tool-surface law, not a session one.
     */
    private function recoverStream(): bool
    {
        $this->closeStream();

        for ($attempt = 1; $attempt <= self::RECONNECT_BUDGET; $attempt++) {
            ($this->sleeper)(min(
                self::RECONNECT_BACKOFF_SECONDS * (2 ** ($attempt - 1)),
                self::RECONNECT_BACKOFF_CAP_SECONDS,
            ));

            try {
                $this->establishSession();

                return true;
            } catch (\Throwable) {
                // The next attempt IS the recovery policy; a burst that
                // cannot re-handshake ends in a refusal, never a loop.
                $this->closeStream();
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function sendPost(array $payload): ResponseInterface
    {
        if ($this->postTarget === null) {
            throw new \RuntimeException('MCP server ' . $this->name . ': no origin-locked POST target — the endpoint handshake never completed');
        }

        return $this->httpClient->post($this->postTarget, $this->connectionOptions() + [
            'json' => $payload,
            'headers' => $this->wireHeaders(),
            // MCP-4 (audit re-run F-1): the bearer and the JSON-RPC body ride
            // this POST; a Location pointing off-origin must not receive them.
            'allow_redirects' => false,
            'http_errors' => false,
            'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
            'timeout' => self::POST_TIMEOUT_SECONDS,
        ]);
    }

    /**
     * The spec's cancellation notification (ISS-042 family): a POST with no
     * id, best-effort twice over — its own short bound, and a failure here
     * discarded because the call already failed and this message must not
     * replace its diagnosis.
     *
     * @param \Throwable|string $reason
     */
    private function sendCancelled(int|string $id, \Throwable|string $reason): void
    {
        if ($this->postTarget === null) {
            return;
        }

        $message = $reason instanceof \Throwable ? $reason->getMessage() : $reason;

        try {
            $this->httpClient->post($this->postTarget, $this->connectionOptions() + [
                'json' => ['jsonrpc' => '2.0', 'method' => 'notifications/cancelled', 'params' => [
                    'requestId' => $id,
                    'reason' => $message,
                ]],
                'headers' => $this->wireHeaders(),
                // MCP-4 (audit re-run F-1): best-effort delivery never means
                // best-effort egress — the bearer still must not hop.
                'allow_redirects' => false,
                'http_errors' => false,
                'timeout' => self::CANCEL_NOTICE_TIMEOUT_SECONDS,
                'connect_timeout' => self::CANCEL_NOTICE_TIMEOUT_SECONDS,
            ]);
        } catch (\Throwable) {
            // Best-effort by contract — see the docblock.
        }
    }

    /**
     * Fork-safety on the POST side, same law as {@see HttpMcpServer}: outside
     * the session's owner process the inherited curl handle still holds the
     * owner's keep-alive socket, so every POST rides a fresh connection that
     * is closed afterwards. (A MockHandler ignores these.)
     *
     * @return array<string, mixed>
     */
    private function connectionOptions(): array
    {
        if ($this->ids->isOwner() || !\defined('CURLOPT_FRESH_CONNECT')) {
            return [];
        }

        return ['curl' => [CURLOPT_FRESH_CONNECT => true, CURLOPT_FORBID_REUSE => true]];
    }

    /**
     * Headers for every message this transport sends: configured statics plus
     * the stored OAuth bearer when one exists for the configured URL, static
     * `Authorization` winning over the store — the E695 precedence and the
     * per-request refresh consult, same law as {@see HttpMcpServer} carries.
     * The store is keyed on the CONFIGURED URL, never on the server-published
     * target: the origin lock already guarantees the two agree in scheme,
     * host and port, and the key an operator logs in with must be the key
     * looked up.
     *
     * @return array<string, string>
     */
    private function requestHeaders(): array
    {
        if ($this->authStore === null || $this->hasStaticAuthorization()) {
            return $this->headers;
        }

        $entry = $this->authStore->oauth()->validAuthFor($this->url);
        if ($entry === null || $entry->accessToken === '') {
            return $this->headers;
        }

        $headers = $this->headers;
        $headers['Authorization'] = 'Bearer ' . $entry->accessToken;

        return $headers;
    }

    private function hasStaticAuthorization(): bool
    {
        foreach (array_keys($this->headers) as $headerName) {
            if (strcasecmp((string) $headerName, 'Authorization') === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Configured/bearer headers minus the transport-owned names — the GET's
     * header set exactly (`Accept` rides the request line above it, and the
     * stream must NOT be advertised as accepting `application/json`).
     *
     * @return array<string, string>
     */
    private function filteredHeaders(): array
    {
        $headers = [];
        foreach ($this->requestHeaders() as $name => $value) {
            if (!in_array(strtolower((string) $name), self::TRANSPORT_OWNED_HEADERS, true)) {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    /**
     * POST headers: the filtered config/bearer set plus this transport's own
     * JSON framing.
     *
     * @return array<string, string>
     */
    private function wireHeaders(): array
    {
        $headers = $this->filteredHeaders();
        $headers['Accept'] = 'application/json';
        $headers['Content-Type'] = 'application/json';

        return $headers;
    }

    // ------------------------------------------------------------------
    // Stream reading (own HTTP/1.1 client half: head + chunked body)
    // ------------------------------------------------------------------

    /**
     * The reader the shared parser pulls through: decoded SSE bytes, `''` at
     * end of stream, every wait bounded by {@see $waitDeadlineAt}.
     */
    private function readStreamChunk(): string
    {
        $budget = self::MAX_STREAM_BYTES_PER_WAIT - $this->fedBytesInWait;
        if ($budget <= 0) {
            throw new \RuntimeException(
                'MCP server ' . $this->name . ': the event stream carried more than '
                . self::MAX_STREAM_BYTES_PER_WAIT . ' bytes while waiting for ' . $this->waitLabel
                . ' — refusing to buffer an unbounded flood',
            );
        }

        $raw = $this->readPendingPayload(min(8192, $budget));
        if ($raw === '') {
            return '';
        }
        $this->fedBytesInWait += strlen($raw);

        return $raw;
    }

    /**
     * Up to $bytes decoded payload bytes; `''` only at true end of stream.
     * Chunked framing (size line, payload, trailer to the terminal zero
     * chunk) or, when the server sends no transfer-encoding, the plain bytes
     * themselves — an SSE body without chunking ends when the socket does.
     */
    private function readPendingPayload(int $bytes): string
    {
        while ($this->payloadBuf === '') {
            if ($this->lastChunkSeen) {
                return '';
            }
            if (!$this->chunked) {
                $raw = $this->readWire($bytes);
                if ($raw === '') {
                    return ''; // an unchunked SSE body ends when the socket does
                }
                $this->payloadBuf = $raw;

                continue;
            }
            if ($this->chunkRemaining === null) {
                $sizeLine = $this->readWireLine();
                if ($sizeLine === null) {
                    return ''; // clean EOF between chunks
                }
                // Extensions (`;name=value`) ride after the hex size; the
                // size terminates at the first ';' or whitespace.
                $hex = strtolower(rtrim(strstr($sizeLine . ';', ';', true), " \t\r\n"));
                if (ctype_xdigit($hex) === false || $hex === '') {
                    throw new \RuntimeException(
                        'MCP server ' . $this->name . ': malformed chunk size line in the event stream',
                    );
                }
                $size = hexdec($hex);
                if ($size < 1) {
                    $this->lastChunkSeen = true;
                    $this->drainTrailer();

                    return '';
                }
                if ($size > self::MAX_STREAM_BYTES_PER_WAIT) {
                    throw new \RuntimeException(
                        'MCP server ' . $this->name . ': a single event-stream chunk claims '
                        . (int) $size . ' bytes — refusing to read it',
                    );
                }
                $this->chunkRemaining = (int) $size;

                continue;
            }
            $want = min($bytes, $this->chunkRemaining);
            $chunk = $this->readWire($want);
            if ($chunk === '') {
                throw new \RuntimeException(
                    'MCP server ' . $this->name . ': the event stream died mid-chunk (expected '
                    . $this->chunkRemaining . ' more bytes)',
                );
            }
            $this->chunkRemaining -= strlen($chunk);
            $this->payloadBuf .= $chunk;
            if ($this->chunkRemaining === 0) {
                // The CRLF that closes every chunk.
                $this->readWire(2);
                $this->chunkRemaining = null;
            }
        }

        $out = substr($this->payloadBuf, 0, $bytes);
        $this->payloadBuf = (string) substr($this->payloadBuf, strlen($out));

        return $out;
    }

    /**
     * Trailer headers after the terminal zero chunk, up to the final blank
     * line. They carry no SSE payload, so they are read and dropped; a stream
     * that ends inside the trailer is EOF, not corruption.
     */
    private function drainTrailer(): void
    {
        while (true) {
            $line = $this->readWireLine();
            if ($line === null || $line === '') {
                return;
            }
        }
    }

    /**
     * @return array{0: int, 1: string, 2: array<string,string>}
     */
    private function readHead(): array
    {
        $this->beginWait(self::OPEN_TIMEOUT_SECONDS, 'the response head');
        try {
            $head = '';
            while (true) {
                $byte = $this->readWireStrict(1);
                $head .= $byte;
                if (str_ends_with($head, "\r\n\r\n")) {
                    break;
                }
                if (strlen($head) >= self::MAX_HEAD_BYTES) {
                    throw new \RuntimeException('the event-stream response head exceeded ' . self::MAX_HEAD_BYTES . ' bytes');
                }
            }
        } finally {
            $this->endWait();
        }

        [$statusLine, $rest] = explode("\r\n", $head, 2);
        if (preg_match('#^HTTP/\d(?:\.\d)? (\d{3}) ?(.*)$#', $statusLine, $m) !== 1) {
            throw new \RuntimeException('the event-stream response carried no parsable status line');
        }

        $headers = [];
        foreach (explode("\r\n", rtrim($rest, "\r\n")) as $line) {
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }

        return [(int) $m[1], trim($m[2]), $headers];
    }

    /**
     * @param array<string,string> $head
     */
    private function assertStreamHead(int $status, string $reason, array $head): void
    {
        if ($status >= 300 && $status < 400) {
            throw new \RuntimeException(
                "HTTP {$status} {$reason}: the event-stream GET was redirected, and this transport"
                . ' does not follow redirects (a redirect could move the stream off the configured origin)',
            );
        }
        if ($status === 401 || $status === 403) {
            throw new HttpMcpAuthRequiredException($this->name, $status);
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(trim("HTTP {$status} {$reason}"));
        }

        $mediaType = strtolower(trim(explode(';', $head['content-type'] ?? '')[0]));
        if ($mediaType !== self::PROTOCOL_STREAM_ACCEPT) {
            throw new \RuntimeException(
                'the event-stream GET was answered with content-type "' . $mediaType . '", not '
                . self::PROTOCOL_STREAM_ACCEPT,
            );
        }
    }

    private function writeRequest(string $request): void
    {
        $socket = $this->requireSocket();
        $deadlineAt = microtime(true) + self::OPEN_TIMEOUT_SECONDS;

        $remaining = $request;
        while ($remaining !== '') {
            $left = $deadlineAt - microtime(true);
            if ($left <= 0.0) {
                throw new \RuntimeException('timed out writing the event-stream GET request');
            }
            $read = null;
            $write = [$socket];
            $except = null;
            $ready = @stream_select($read, $write, $except, (int) $left, (int) (($left - (int) $left) * 1000000));
            if ($ready === false) {
                throw new \RuntimeException('the event-stream connection failed while the GET request was being written');
            }
            if ($ready === 0) {
                continue;
            }
            $written = @fwrite($socket, $remaining);
            if ($written === false || ($written === 0 && feof($socket))) {
                throw new \RuntimeException('the event-stream connection was closed while the GET request was being written');
            }
            if ($written > 0) {
                $remaining = substr($remaining, $written);
            }
        }
    }

    /**
     * Read whatever the socket carries, blocking at most until the active
     * wait deadline. `''` answers mean the peer closed the stream.
     */
    private function readWire(int $bytes): string
    {
        $socket = $this->requireSocket();

        while (true) {
            $remaining = ($this->waitDeadlineAt ?? microtime(true) + self::OPEN_TIMEOUT_SECONDS) - microtime(true);
            if ($remaining <= 0.0) {
                throw new SseStreamDeadlineException("timed out after its wait bound while waiting for {$this->waitLabel}");
            }

            $read = [$socket];
            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, (int) $remaining, (int) (($remaining - (int) $remaining) * 1000000));
            if ($ready === false) {
                throw new \RuntimeException('the event-stream socket failed while waiting for ' . $this->waitLabel);
            }
            if ($ready === 0) {
                continue; // spurious readiness; the deadline decides, not this tick
            }

            $chunk = @fread($socket, $bytes);
            if ($chunk === false) {
                if (feof($socket)) {
                    return '';
                }
                continue;
            }
            if ($chunk !== '') {
                return $chunk;
            }
            if (feof($socket)) {
                return '';
            }
        }
    }

    private function readWireStrict(int $bytes): string
    {
        $chunk = $this->readWire($bytes);
        if ($chunk === '') {
            throw new \RuntimeException("the event stream closed while waiting for {$this->waitLabel}");
        }

        return $chunk;
    }

    /**
     * One CRLF/LF-terminated head-ish line from the wire (chunk sizes,
     * trailers). Null on clean EOF so callers can distinguish "stream ended"
     * from "line read"; a line past the bound is a malformed stream.
     */
    private function readWireLine(): ?string
    {
        $line = '';
        while (true) {
            $byte = $this->readWire(1);
            if ($byte === '') {
                return $line === '' ? null : $line;
            }
            if ($byte === "\n") {
                return rtrim($line, "\r");
            }
            $line .= $byte;
            if (strlen($line) >= self::MAX_LINE_BYTES) {
                throw new \RuntimeException('the event stream carried a framing line past ' . self::MAX_LINE_BYTES . ' bytes');
            }
        }
    }

    /**
     * @return resource
     */
    private function requireSocket()
    {
        if (!\is_resource($this->socket)) {
            throw new \RuntimeException('the event stream is not open');
        }

        return $this->socket;
    }

    private function closeStream(): void
    {
        if (\is_resource($this->socket)) {
            $this->closeSocket($this->socket);
        }
        $this->socket = null;
        $this->parser = null;
        $this->postTarget = null;
        $this->sessionPid = null;
        $this->payloadBuf = '';
        $this->chunkRemaining = null;
        $this->lastChunkSeen = false;
    }

    /** @param resource $socket */
    private function closeSocket($socket): void
    {
        @fclose($socket);
    }

    /**
     * The default connector. TLS via php streams with peer verification and
     * SNI on — no option here is a relaxation of what Guzzle's curl handler
     * did by default; the transport moved, the trust posture did not. (Proxy
     * configuration is honoured by neither the moved GET nor any prior sse
     * path; documented in docs/MCP.md.)
     *
     * @return resource
     */
    private static function connectSocket(string $scheme, string $host, int $port, float $timeoutSeconds)
    {
        $transport = $scheme === 'https' ? 'tls' : 'tcp';
        $context = stream_context_create([
            'ssl' => [
                'peer_name' => $host,
                'SNI_enabled' => true,
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $socket = @stream_socket_client(
            "{$transport}://{$host}:{$port}",
            $errno,
            $errstr,
            $timeoutSeconds,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($socket === false) {
            throw new \RuntimeException("cannot connect to {$transport}://{$host}:{$port}: {$errstr} ({$errno})");
        }
        stream_set_read_buffer($socket, 0);
        stream_set_write_buffer($socket, 0);

        return $socket;
    }

    // ------------------------------------------------------------------
    // Reply-shape gates (the wording family every transport shares)
    // ------------------------------------------------------------------

    /**
     * @param array<mixed>|null $data
     * @return array<McpTool>
     */
    private function collectToolPages(?array $data): array
    {
        $tools = $this->requireToolsListPage($data, 1);
        $seenCursors = [];

        for ($page = 2; $page <= self::MAX_TOOL_PAGES; $page++) {
            $cursor = $data['result']['nextCursor'] ?? null;

            if (!is_string($cursor) || $cursor === '') {
                return $tools;
            }

            if (isset($seenCursors[$cursor])) {
                throw new \RuntimeException(
                    'tools/list repeated cursor ' . $cursor . ' at page ' . $page
                    . ' — refusing to walk a pagination loop',
                );
            }
            $seenCursors[$cursor] = true;

            $data = $this->exchange('tools/list', ['cursor' => $cursor]);
            $tools = array_merge($tools, $this->requireToolsListPage($data, $page));
        }

        throw new \RuntimeException('tools/list still had a nextCursor after '
            . self::MAX_TOOL_PAGES . ' pages — refusing to cache a truncated roster');
    }

    /**
     * @param array<mixed>|null $data
     * @return array<McpTool>
     */
    private function requireToolsListPage(?array $data, int $page): array
    {
        $suffix = $page === 1 ? '' : ' (page ' . $page . ')';

        if ($data === null) {
            throw new \RuntimeException('tools/list returned an invalid response' . $suffix);
        }

        if (isset($data['error'])) {
            throw new \RuntimeException('tools/list refused' . self::describeError($data['error']) . $suffix);
        }

        if (!array_key_exists('result', $data)) {
            throw new \RuntimeException('tools/list returned no result' . $suffix);
        }

        return $this->parseTools($data);
    }

    /**
     * Walk a paginated provider list to its end. MCP-4 (audit re-run F-2):
     * the loop and the envelope gate are the ONE shared
     * {@see WalksProviderPages} implementation; this class contributes only
     * its wire call and its exact refusal bytes (same wording family as
     * HttpMcpServer's).
     *
     * @return list<array<mixed>>
     */
    private function collectProviderPages(string $method, string $key): array
    {
        return $this->walkProviderPages(
            $key,
            self::MAX_TOOL_PAGES,
            function (?array $params) use ($method): array {
                return $this->requireProviderResult($method, $this->exchange($method, $params));
            },
            fn (string $cursor, int $page): string => $method . ' repeated cursor ' . $cursor . ' at page ' . $page
                . ' — refusing to walk a pagination loop',
            fn (): string => $method . ' still had a nextCursor after '
                . self::MAX_TOOL_PAGES . ' pages — refusing to return a truncated roster',
        );
    }

    /**
     * A POST response is delivery only: any 2xx is accepted. A 404 cannot
     * mean "session expired" here (legacy servers have no session id to
     * expire), so every non-2xx is the server refusing the message itself.
     */
    private function assertSuccess(ResponseInterface $response): void
    {
        $status = $response->getStatusCode();
        if ($status >= 200 && $status < 300) {
            return;
        }

        $body = '';
        try {
            $body = (string) $response->getBody()->read(4096);
        } catch (\Throwable) {
            // A body that will not be read simply leaves the status alone.
        }
        try {
            $response->getBody()->close();
        } catch (\Throwable) {
        }

        if ($status === 401 || $status === 403) {
            throw new HttpMcpAuthRequiredException($this->name, $status);
        }

        // MCP-4 (audit re-run F-1): a 3xx is refused by name, never relayed as
        // the body's words — sendPost() refused to follow it, and the refusal
        // belongs to the redirect itself. Mirrors the event-stream GET gate in
        // assertStreamHead(), POST-side.
        if ($status >= 300 && $status < 400) {
            throw new \RuntimeException(trim("HTTP {$status} {$response->getReasonPhrase()}")
                . ': the endpoint POST was redirected, and this transport does not follow redirects'
                . ' (a redirect could carry the bearer and the request body off the configured origin)');
        }

        $decoded = json_decode($body, true);
        $detail = is_array($decoded) && isset($decoded['error'])
            ? self::describeError($decoded['error'])
            : ($body === '' ? '' : ': ' . mb_strimwidth(trim($body), 0, 200, '…'));

        throw new \RuntimeException(trim("HTTP {$status} {$response->getReasonPhrase()}") . $detail);
    }

    /**
     * `" (code): message"` for a JSON-RPC error member, whatever its shape —
     * the formatter family {@see HttpMcpServer::describeError()} carries.
     */
    private static function describeError(mixed $error): string
    {
        if (!is_array($error)) {
            return ': ' . (is_string($error) ? $error : (string) json_encode($error, JSON_PARTIAL_OUTPUT_ON_ERROR));
        }

        $code = $error['code'] ?? null;
        $message = $error['message'] ?? null;

        return sprintf(
            '%s: %s',
            is_int($code) || is_string($code) ? " ({$code})" : '',
            is_string($message) && $message !== '' ? $message : (string) json_encode($error, JSON_PARTIAL_OUTPUT_ON_ERROR),
        );
    }

    /**
     * @param array<mixed> $response
     * @return array<McpTool>
     */
    private function parseTools(array $response): array
    {
        $tools = [];
        $toolDefs = $response['result']['tools'] ?? [];

        if (!is_array($toolDefs)) {
            $toolDefs = [];
        }

        foreach ($toolDefs as $def) {
            if (!is_array($def)) {
                continue;
            }

            $tool = McpTool::tryFromArray($def, $this->name);
            if ($tool !== null) {
                $tools[] = $tool;
            }
        }

        return $tools;
    }

    // ------------------------------------------------------------------
    // Wait bookkeeping
    // ------------------------------------------------------------------

    private function beginWait(float $seconds, string $label): void
    {
        $this->waitDeadlineAt = microtime(true) + $seconds;
        $this->waitLabel = $label;
        $this->fedBytesInWait = 0;
    }

    private function endWait(): void
    {
        $this->waitDeadlineAt = null;
        $this->waitLabel = '';
    }
}

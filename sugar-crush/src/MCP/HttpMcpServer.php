<?php

declare(strict_types=1);

namespace SugarCraft\Crush\MCP;

use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use SugarCraft\Mcp\ArgumentShape;
use SugarCraft\Mcp\RequestIdSequence;

/**
 * Client half of the MCP **Streamable HTTP** transport: one endpoint URL, every
 * client message a POST, the reply either a JSON body or a `text/event-stream`
 * whose `data:` frames carry it.
 *
 * Audit MCP-2: this class used to send bare stateless POSTs, and no Streamable
 * HTTP server would complete a handshake with it — no `Accept` header (406 Not
 * Acceptable), no `Mcp-Session-Id` echo (400 "No valid session ID"), no
 * `notifications/initialized`, an SSE reply json_decoded into "invalid
 * response", and `[]` on the wire where the schema wants `{}`.
 *
 * FORK SAFETY (audit AG-1). sugar-crush starts servers in the TUI parent and
 * calls them from forked turn/sub-agent processes. The object a child inherits
 * carries the parent's id counter AND the parent's Guzzle client, whose curl
 * handle keeps the parent's keep-alive socket: three children posting at once
 * wrote to that one socket and each read a sibling's reply (measured). So a
 * process other than the one that started the session (a) mints pid-tagged
 * request ids ({@see RequestIdSequence}) — the reply matcher compares ids as
 * strings — and (b) sends every request on a FRESH connection that is closed
 * afterwards, never the inherited socket. The `Mcp-Session-Id` stays shared:
 * one session carrying many concurrent requests with distinct ids is legal
 * Streamable HTTP. Only the owner ends the session in {@see stop()}.
 */
final class HttpMcpServer implements McpServer
{
    /** The version this client advertises in `initialize` (unchanged by MCP-2). */
    public const PROTOCOL_VERSION = '2024-11-05';

    /**
     * Both media types, always: a Streamable HTTP server MUST answer a POST
     * that does not accept both with 406, and may pick either for its reply.
     */
    public const ACCEPT = 'application/json, text/event-stream';

    /**
     * The `MCP-Protocol-Version` request header is a 2025-06-18 addition; a
     * session negotiated at an older revision does not send it (an older
     * server never reads it, and a newer one assumes 2025-03-26 without it).
     */
    private const PROTOCOL_VERSION_HEADER_SINCE = '2025-06-18';

    /**
     * Headers the transport owns. An operator-configured header of one of
     * these names (case-insensitive) is DROPPED, not merged: a config
     * `Accept: application/json` is exactly the 406 MCP-2 measured, and a
     * hand-set session id or protocol version would pin a session the server
     * hands out per launch.
     */
    private const TRANSPORT_OWNED_HEADERS = ['accept', 'content-type', 'mcp-session-id', 'mcp-protocol-version'];

    /**
     * Bound for the best-effort session DELETE in {@see stop()}: teardown must
     * not hold an exiting process for the client's full request timeout.
     */
    private const DELETE_TIMEOUT_SECONDS = 5.0;

    /** @var array<McpTool> */
    private array $tools = [];

    private bool $initialized = false;

    /**
     * JSON-RPC request ids — never reused within a session, and unique across
     * forked processes (integers for the owner, pid-tagged strings otherwise).
     */
    private readonly RequestIdSequence $ids;

    /** Session id the server assigned in its `initialize` reply, if any. */
    private ?string $sessionId = null;

    /** `protocolVersion` the server answered `initialize` with. */
    private ?string $negotiatedVersion = null;

    /**
     * @param \Closure|null $pidProvider @internal test seam returning the
     *        current pid (default getmypid()), so the forked-process id and
     *        connection behaviour can be pinned without forking
     */
    public function __construct(
        public readonly string $name,
        private string $url,
        private array $headers,
        private Client $httpClient,
        private readonly ?McpAuthStore $authStore = null,
        ?\Closure $pidProvider = null,
    ) {
        $this->ids = new RequestIdSequence($pidProvider);
    }

    public function start(): void
    {
        // Idempotent: a started server must not re-issue the HTTP handshake.
        if ($this->initialized) {
            return;
        }

        // The process that opens the session owns it (ids, connection reuse,
        // and the DELETE that ends it).
        $this->ids->claim();

        try {
            $this->handshake();

            // params omitted rather than sent as `[]` (a JSON array, which the
            // SDK servers reject) — omission is what the reference TS client
            // sends. No session-expiry recovery here: the session is seconds old.
            $data = $this->exchange('tools/list', null);
            // A non-JSON / non-object body (e.g. an HTTP 5xx error page) means the
            // tools/list leg of the handshake failed — surface it as a start failure.
            if ($data === null) {
                throw new \RuntimeException('tools/list returned an invalid response');
            }
            $this->tools = $this->parseTools($data);
        } catch (\Exception $e) {
            $this->sessionId = null;
            $this->negotiatedVersion = null;

            throw new \RuntimeException("Failed to start MCP server {$this->name}: {$e->getMessage()}");
        }

        // Mark initialized only after the full handshake succeeds, so a failed
        // start can be retried rather than wedging the server half-open.
        $this->initialized = true;
    }

    /**
     * End the session. A server that issued an `Mcp-Session-Id` is told so
     * with an HTTP DELETE carrying it (the spec's explicit termination);
     * that is best-effort — a 405 (server does not allow client-side
     * termination), a network error or a dead host must not fail a shutdown.
     * A server that issued no session id has nothing to tear down and gets
     * no request at all.
     */
    public function stop(): void
    {
        // A forked process shares the owner's session: ending it here would cut
        // the session out from under the parent and every sibling. Forget it
        // locally instead.
        if ($this->sessionId !== null && $this->ids->isOwner()) {
            try {
                $this->httpClient->delete($this->url, [
                    'headers' => $this->wireHeaders(),
                    'http_errors' => false,
                    'timeout' => self::DELETE_TIMEOUT_SECONDS,
                ]);
            } catch (\Exception) {
                // Best-effort by contract — see the docblock.
            }
        }

        $this->sessionId = null;
        $this->negotiatedVersion = null;
        $this->initialized = false;
    }

    /**
     * E698: for an HTTP server, "up" IS {@see $initialized} — the handshake
     * completed and the tool list was cached, and {@see stop()} has not ended
     * the session since. There is no process to lose and no persistent
     * connection to drop; each call is its own request, so this answers "did
     * this launch ever talk to it", not "is it answering right now" (that
     * question would cost a wire exchange, which a readout must not).
     */
    public function isUp(): bool
    {
        return $this->initialized;
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
            $data = $this->request('tools/call', [
                'name' => $toolName,
                // An argument-less call arrives as PHP `[]`, which encodes as a
                // JSON array; `arguments` is an object in the schema and the
                // SDK servers reject the array form (audit MCP-1/MCP-2). Nested
                // empty objects lost the same way are restored against the
                // tool's inputSchema (audit MCP-9, {@see ArgumentShape}).
                'arguments' => $args === [] ? new \stdClass() : ArgumentShape::conform($args, $this->inputSchemaOf($toolName)),
            ]);

            return $data !== null ? ($data['result'] ?? ['error' => 'Tool call failed']) : ['error' => 'Invalid response'];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * The inputSchema the tool advertised at start(), or `[]` for a name the
     * table does not hold (ArgumentShape then converts nothing).
     *
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
     * The lifecycle's first two legs: `initialize`, then — only once the
     * server has ANSWERED it with a result — `notifications/initialized`.
     * Re-run verbatim when a session expires, so it starts from no session.
     */
    private function handshake(): void
    {
        $this->sessionId = null;
        $this->negotiatedVersion = null;

        $reply = $this->exchange('initialize', [
            'protocolVersion' => self::PROTOCOL_VERSION,
            // capabilities is a JSON object in the schema; PHP's `[]` would
            // reach the wire as an array, which the SDK servers refuse.
            'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 'sugar-crush', 'version' => '1.0.0'],
        ]);

        if ($reply === null) {
            throw new \RuntimeException('initialize returned an invalid response');
        }

        // An error reply is the server refusing the session, not a start: it
        // will not serve tools to a session it never initialized. Its own
        // code and message are the most useful diagnostic there is.
        if (isset($reply['error'])) {
            throw new \RuntimeException('initialize refused' . self::describeError($reply['error']));
        }

        if (!isset($reply['result']) || !is_array($reply['result'])) {
            throw new \RuntimeException('initialize returned no result');
        }

        $version = $reply['result']['protocolVersion'] ?? null;
        $this->negotiatedVersion = is_string($version) && $version !== '' ? $version : null;

        // The spec's notification name, a POST with no id. A compliant server
        // answers 202 Accepted with no body; any other 2xx is tolerated, a
        // non-2xx is the server refusing the session.
        $response = $this->send(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
        $this->assertSuccess($response);
    }

    /**
     * A request on an ESTABLISHED session, with the spec's expiry recovery:
     * a 404 to a request that carried an `Mcp-Session-Id` means the server
     * has forgotten that session, and the client MUST start a new one. That
     * is done once — re-handshake, resend — and a second failure surfaces.
     * Resending is safe because a 404 means the server never processed it.
     *
     * @param array<string, mixed>|null $params
     * @return array<mixed>|null
     */
    private function request(string $method, ?array $params): ?array
    {
        $carriedSession = $this->sessionId !== null;
        [$id, $response] = $this->dispatch($method, $params);

        if ($carriedSession && $response->getStatusCode() === 404) {
            $this->closeBody($response);
            try {
                $this->handshake();
            } catch (\Exception $e) {
                throw new \RuntimeException("MCP session expired and re-initialize failed: {$e->getMessage()}", 0, $e);
            }
            [$id, $response] = $this->dispatch($method, $params);
        }

        return $this->replyFor($response, $id);
    }

    /**
     * One request/reply round trip with no session-expiry recovery.
     *
     * @param array<string, mixed>|null $params
     * @return array<mixed>|null
     */
    private function exchange(string $method, ?array $params): ?array
    {
        [$id, $response] = $this->dispatch($method, $params);

        return $this->replyFor($response, $id);
    }

    /**
     * POST one JSON-RPC request. The `initialize` reply's `Mcp-Session-Id` is
     * captured here, before the body is read, so every later request echoes it.
     *
     * @param array<string, mixed>|null $params
     * @return array{0: int|string, 1: ResponseInterface}
     */
    private function dispatch(string $method, ?array $params): array
    {
        $id = $this->nextRequestId();
        $payload = ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method];
        if ($params !== null) {
            $payload['params'] = $params;
        }

        $response = $this->send($payload);

        if ($method === 'initialize') {
            $sessionId = trim($response->getHeaderLine('Mcp-Session-Id'));
            $this->sessionId = $sessionId !== '' ? $sessionId : null;
        }

        return [$id, $response];
    }

    /**
     * The next JSON-RPC request id. The ONE place ids are minted: an integer
     * in the process that started the session, `<pid>-<nonce>-<n>` in any
     * other (see the FORK SAFETY note on the class).
     */
    private function nextRequestId(): int|string
    {
        return $this->ids->next();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function send(array $payload): ResponseInterface
    {
        return $this->httpClient->post($this->url, $this->connectionOptions() + [
            'json' => $payload,
            'headers' => $this->wireHeaders(),
            // Statuses are this class's to interpret: a 404 on a session-bearing
            // request is the expiry signal, not a generic failure.
            'http_errors' => false,
            // NOT 'stream' => true: Guzzle serves streamed bodies through PHP's
            // http wrapper, whose dechunking filter blocks a read until its
            // buffer fills or the socket closes — MEASURED against
            // server-everything, every SSE reply then waited out Node's 5s
            // keep-alive. The buffered curl path returns when the reply ends,
            // and the spec has the server close an SSE reply stream once the
            // response is sent (both reference SDKs do).
        ]);
    }

    /**
     * Per-request transport options. In a process other than the session's
     * owner the inherited curl handle still holds the OWNER's keep-alive
     * socket; reusing it lets two processes interleave on one TCP stream and
     * read each other's replies. A fresh connection, closed after use, keeps
     * each process on its own socket. (A MockHandler ignores these.)
     *
     * @return array<string, mixed>
     */
    private function connectionOptions(): array
    {
        // Without ext-curl Guzzle falls back to PHP's stream wrapper, which
        // opens a new connection per request anyway — nothing to inherit.
        if ($this->ids->isOwner() || !\defined('CURLOPT_FRESH_CONNECT')) {
            return [];
        }

        return ['curl' => [CURLOPT_FRESH_CONNECT => true, CURLOPT_FORBID_REUSE => true]];
    }

    /**
     * Everything that goes on the wire: the configured statics plus any
     * stored bearer ({@see requestHeaders()}), minus the transport-owned
     * names, plus the transport's own values for them.
     *
     * @return array<string, string>
     */
    private function wireHeaders(): array
    {
        $headers = [];
        foreach ($this->requestHeaders() as $name => $value) {
            if (!in_array(strtolower((string) $name), self::TRANSPORT_OWNED_HEADERS, true)) {
                $headers[$name] = $value;
            }
        }

        $headers['Accept'] = self::ACCEPT;
        $headers['Content-Type'] = 'application/json';

        if ($this->sessionId !== null) {
            $headers['Mcp-Session-Id'] = $this->sessionId;
        }

        if ($this->negotiatedVersion !== null
            && strcmp($this->negotiatedVersion, self::PROTOCOL_VERSION_HEADER_SINCE) >= 0) {
            $headers['MCP-Protocol-Version'] = $this->negotiatedVersion;
        }

        return $headers;
    }

    /**
     * The JSON-RPC response to request $id, from either reply shape the spec
     * allows: an `application/json` body or a `text/event-stream`.
     *
     * A single JSON object is taken as THE reply (one request, one response);
     * a JSON array is a batch and the entry carrying $id is picked. Null when
     * the body is not JSON-RPC at all.
     *
     * @return array<mixed>|null
     */
    private function replyFor(ResponseInterface $response, int|string $id): ?array
    {
        $this->assertSuccess($response);

        if (self::isEventStream($response)) {
            return $this->replyFromEventStream($response->getBody(), $id);
        }

        $data = json_decode($response->getBody()->getContents(), true);
        if (!is_array($data) || $data === []) {
            return null;
        }

        return array_is_list($data) ? self::matchReply($data, $id) : $data;
    }

    /**
     * Read SSE events until the one carrying the response to $id (the body is
     * already buffered — see {@see send()} — so this is a parse, not a wait).
     *
     * Per the SSE format: lines end in CRLF, LF or CR; `:` lines are comments;
     * `data:` lines accumulate and join with "\n"; a blank line dispatches the
     * event; one leading space after the colon is dropped. Only `message`
     * events (the default type) carry JSON-RPC. The stream may interleave
     * server notifications and server-to-client requests before the response —
     * those are skipped. A trailing event left unterminated by end-of-stream is
     * still examined: a server that closes right after its last data line has
     * said what it meant to.
     *
     * @return array<mixed>
     */
    private function replyFromEventStream(StreamInterface $body, int|string $id): array
    {
        $state = ['event' => '', 'data' => []];
        $buffer = '';

        try {
            while (true) {
                $chunk = $body->eof() ? '' : $body->read(8192);
                $atEnd = $chunk === '';
                $buffer .= $chunk;

                while (preg_match('/\r\n|\n|\r/', $buffer, $m, PREG_OFFSET_CAPTURE) === 1) {
                    [$terminator, $offset] = $m[0];
                    // A lone CR at the very end may be the first half of a CRLF
                    // split across reads — wait for the next chunk to decide.
                    if ($terminator === "\r" && $offset === strlen($buffer) - 1 && !$atEnd) {
                        break;
                    }
                    $line = substr($buffer, 0, $offset);
                    $buffer = (string) substr($buffer, $offset + strlen($terminator));

                    $reply = self::consumeSseLine($line, $state, $id);
                    if ($reply !== null) {
                        return $reply;
                    }
                }

                if ($atEnd) {
                    break;
                }
            }

            if ($buffer !== '') {
                $reply = self::consumeSseLine($buffer, $state, $id);
                if ($reply !== null) {
                    return $reply;
                }
            }
            $reply = self::consumeSseLine('', $state, $id);
            if ($reply !== null) {
                return $reply;
            }
        } finally {
            $body->close();
        }

        throw new \RuntimeException("event stream ended without a response to request {$id}");
    }

    /**
     * Feed one SSE line into the event being assembled; return the reply to
     * $id when this line completes the event that carries it.
     *
     * @param array{event: string, data: list<string>} $state
     * @return array<mixed>|null
     */
    private static function consumeSseLine(string $line, array &$state, int|string $id): ?array
    {
        if ($line === '') {
            $event = $state['event'];
            $data = $state['data'];
            $state = ['event' => '', 'data' => []];

            if ($data === [] || ($event !== '' && $event !== 'message')) {
                return null;
            }

            $decoded = json_decode(implode("\n", $data), true);
            if (!is_array($decoded)) {
                return null;
            }

            return self::matchReply(array_is_list($decoded) ? $decoded : [$decoded], $id);
        }

        if ($line[0] === ':') {
            return null; // comment / keep-alive
        }

        $colon = strpos($line, ':');
        $field = $colon === false ? $line : substr($line, 0, $colon);
        $value = $colon === false ? '' : substr($line, $colon + 1);
        if (str_starts_with($value, ' ')) {
            $value = substr($value, 1);
        }

        if ($field === 'data') {
            $state['data'][] = $value;
        } elseif ($field === 'event') {
            $state['event'] = $value;
        }

        return null;
    }

    /**
     * The response to $id among JSON-RPC messages: an entry with our id and a
     * `result` or `error`, and no `method` (a server-to-client request may
     * reuse an id value from its own sequence).
     *
     * @param list<mixed> $messages
     * @return array<mixed>|null
     */
    private static function matchReply(array $messages, int|string $id): ?array
    {
        foreach ($messages as $message) {
            if (!is_array($message) || isset($message['method']) || !array_key_exists('id', $message)) {
                continue;
            }
            if (!array_key_exists('result', $message) && !array_key_exists('error', $message)) {
                continue;
            }
            $messageId = $message['id'];
            if ((is_int($messageId) || is_string($messageId)) && (string) $messageId === (string) $id) {
                return $message;
            }
        }

        return null;
    }

    private static function isEventStream(ResponseInterface $response): bool
    {
        $mediaType = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0]));

        return $mediaType === 'text/event-stream';
    }

    /**
     * Fail on a non-2xx status, naming the server's own words: a JSON-RPC
     * `error.message` when the body carries one (Streamable HTTP servers send
     * their 400/404/406 refusals that way), else a short body excerpt.
     */
    private function assertSuccess(ResponseInterface $response): void
    {
        $status = $response->getStatusCode();
        if ($status >= 200 && $status < 300) {
            return;
        }

        $body = (string) $response->getBody()->read(4096);
        $this->closeBody($response);

        $decoded = json_decode($body, true);
        $detail = is_array($decoded) && isset($decoded['error'])
            ? self::describeError($decoded['error'])
            : ($body === '' ? '' : ': ' . mb_strimwidth(trim($body), 0, 200, '…'));

        throw new \RuntimeException(trim("HTTP {$status} {$response->getReasonPhrase()}") . $detail);
    }

    /** `" (code): message"` for a JSON-RPC error member, whatever its shape. */
    private static function describeError(mixed $error): string
    {
        if (!is_array($error)) {
            return ': ' . (is_string($error) ? $error : (string) json_encode($error));
        }

        $code = $error['code'] ?? null;
        $message = $error['message'] ?? null;

        return sprintf(
            '%s: %s',
            is_int($code) || is_string($code) ? " ({$code})" : '',
            is_string($message) && $message !== '' ? $message : (string) json_encode($error),
        );
    }

    private function closeBody(ResponseInterface $response): void
    {
        try {
            $response->getBody()->close();
        } catch (\Throwable) {
            // Closing a body that is already gone is not a failure.
        }
    }

    /**
     * E695: the request headers for THIS request — the configured statics,
     * plus the stored OAuth bearer token when one exists for this server URL.
     *
     * WHY PER-REQUEST and not snapshotted at construction: {@see
     * OAuthClientRegistration::validAuthFor()} consults {@see
     * OAuthClientRegistration::getValidAuth()}, which REFRESHES a token inside
     * its expiry buffer and persists the rotation through the store's existing
     * save path. A launch-time snapshot would freeze the first token and send
     * an expired bearer for the rest of the session — the exact inert-store
     * defect this change closes. The steady-state cost is a memoized in-memory
     * lookup ({@see OAuthClientRegistration::loadAuth()}); network traffic
     * happens only on an actual refresh.
     *
     * PRECEDENCE: an `Authorization` header the operator put in `.mcp.json`
     * (already env-resolved by {@see McpClient::resolveEnv()}) WINS over the
     * store — explicit per-launch configuration beats ambient stored state,
     * and the store is not consulted at all in that case (no refresh, no
     * surprise rotation while an operator is deliberately hand-rolling the
     * header). The name is matched case-insensitively because HTTP field
     * names are case-insensitive and the config array is operator-typed.
     *
     * LEAK HYGIENE: the token is placed ONLY into the Guzzle request options
     * — the wire. No message, log, or exception path here interpolates it.
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

    /**
     * True when the static config headers already carry an Authorization field.
     */
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
     * Turn a `tools/list` reply into {@see McpTool}s, SKIPPING the entries the
     * remote got wrong instead of failing the server over them.
     *
     * ⚠️ `is_array($def)` ALONE WAS NOT ENOUGH HERE EITHER, AND THIS HALF STAYED
     * OPEN A ROUND LONGER THAN ITS TWIN. {@see \SugarCraft\Mcp\StdioMcpServer::parseTools()} was
     * given a type filter for the measured `{"tools":[{"name":5}]}` kill; this
     * method was character-identical to the version that had the gap, and the
     * gap is WORSE over HTTP than over stdio in one specific way: {@see start()}
     * wraps its whole handshake in `catch (\Exception)`, and a `TypeError` is an
     * `\Error`, so it walked straight past the one guard this class has.
     * MEASURED at this tree (PHP 8.3.6, Linux 6.8), a two-server `.mcp.json` with
     * a MockHandler-backed client, bad server first:
     *
     *     startServers() ESCAPED: TypeError ... Argument #1 ($name) must be of
     *     type string, int given
     *     -> tools visible: 0, the well-formed second server never started
     *
     * The filter itself lives on {@see McpTool::tryFromArray()} rather than being
     * copied from the stdio class, so the mirror of `fromArray()`'s subscripts
     * exists once. See that method for the reasoning and the `isset()` note.
     *
     * @param array<mixed> $response
     * @return array<McpTool>
     */
    private function parseTools(array $response): array
    {
        $tools = [];
        $toolDefs = $response['result']['tools'] ?? [];

        // THE CONTAINER, not the entries. `?? []` only covers `tools` being
        // ABSENT or null; a peer that sends `{"result":{"tools":"nope"}}` gets
        // past it with a string, and `foreach` over a string is a PHP warning
        // (measured, PHP 8.3.6) plus zero iterations. Same family as the
        // `is_array($def)` skip below, one level up.
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
}

<?php

declare(strict_types=1);

namespace SugarCraft\Code\MCP;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use SugarCraft\McpClient\ArgumentShape;
use SugarCraft\McpClient\RequestIdSequence;

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
 * FORK SAFETY (audit AG-1). candy-code starts servers in the TUI parent and
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
final class HttpMcpServer implements McpServer, McpResourceProvider, McpPromptProvider
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
     * Bound for the best-effort session DELETE ({@see endSession()}): teardown must
     * not hold an exiting process for the client's full request timeout.
     */
    private const DELETE_TIMEOUT_SECONDS = 5.0;

    /**
     * ISS-042: the per-`tools/call` ceiling, in seconds, when the entry names
     * no `toolTimeout` — deliberately {@see StdioMcpServer::DEFAULT_TOOL_TIMEOUT_SECONDS}'s
     * SAME 120, so the two bounded transports stop answering the same question
     * with two different numbers. WHAT IT REPLACED: nothing — every HTTP
     * request rode the shared Guzzle client's fixed 30 s TOTAL timeout, which
     * was simultaneously too short (a slow real tool call died at 30 s while
     * its stdio twin got 120 s) and un-configurable (the entry's `toolTimeout`
     * was read for `stdio` entries only). Only a positive finite number
     * overrides it, same policy as `startTimeout`: a hand-edited config must
     * not be able to turn a bound off by accident.
     */
    public const DEFAULT_TOOL_TIMEOUT_SECONDS = StdioMcpServer::DEFAULT_TOOL_TIMEOUT_SECONDS;

    /**
     * ISS-042: how long a TCP+TLS connection may take before the request is
     * abandoned. Named separately from {@see DEFAULT_TOOL_TIMEOUT_SECONDS}
     * because a dead host must cost seconds, not the whole call budget — the
     * 120 s belongs to the server's WORK, not to finding the server.
     */
    private const CONNECT_TIMEOUT_SECONDS = 10.0;

    /**
     * Budget for delivering `notifications/cancelled` after a call was
     * abandoned at the ceiling. The mirror of the stdio library's own
     * 2-second cancel leg ({@see \SugarCraft\McpClient\StdioMcpServer});
     * a server that never reads it must not add its own timeout to ours.
     */
    private const CANCEL_NOTICE_TIMEOUT_SECONDS = 2.0;

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
        // ISS-042: the per-`tools/call` ceiling. Resolved by
        // {@see \SugarCraft\Code\MCP\McpClient::buildServer()} exactly like the
        // stdio arm's, from the entry's `toolTimeout`; the base Guzzle client's
        // own fixed 30 s is OVERRIDDEN per request, never mutated.
        private readonly float $toolTimeoutSeconds = self::DEFAULT_TOOL_TIMEOUT_SECONDS,
    ) {
        $this->ids = new RequestIdSequence($pidProvider);
    }

    /** The endpoint this server was configured with (ISS-042: the auth
     *  notice must print the URL `mcp auth login` keys the store on). */
    public function endpoint(): string
    {
        return $this->url;
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
            // The reply is gated and its cursor pages followed in
            // {@see collectToolPages()} (ISS-093(b)).
            $this->tools = $this->collectToolPages($this->exchange('tools/list', null));
        } catch (HttpMcpAuthRequiredException $e) {
            // ISS-042: the session leg that refused may already hold a
            // session id (a bearer rejected AFTER initialize, at tools/list)
            // — end it exactly as the generic path below does, then let the
            // typed refusal OUT, where {@see McpClient::startServer()} routes
            // it to the launch notice instead of the silent skip.
            $this->endSession();

            throw $e;
        } catch (\Exception $e) {
            // A failure AFTER the server issued a session id (the
            // notifications/initialized leg, the tools/list gate) leaves that
            // session open on the server unless it is ended here: nothing
            // else ever will, since stop() finds no session to end once it is
            // forgotten locally. Ended the way stop() ends one — best-effort.
            $this->endSession();

            throw new \RuntimeException("Failed to start MCP server {$this->name}: {$e->getMessage()}");
        }

        // Mark initialized only after the full handshake succeeds, so a failed
        // start can be retried rather than wedging the server half-open.
        $this->initialized = true;
    }

    /**
     * Page ceiling for the `tools/list` cursor walk (ISS-093(b)).
     *
     * A wall for pathological servers, not a working budget: 32 pages times a
     * typical page of a hundred tools is three thousand entries, far past any
     * real server. The guard that actually catches a broken server is the
     * repeated-cursor refusal below — this one catches the adversarial shape
     * of a server inventing a fresh cursor forever, which would otherwise
     * spin the handshake (and, since start() holds no deadline by E646
     * design, spin it until the transport's own exchange budget says stop).
     */
    private const MAX_TOOL_PAGES = 32;

    /**
     * Gate a `tools/list` reply and FOLLOW ITS CURSOR until the roster is whole.
     *
     * (ISS-093(b)) The spec says `tools/list` MAY paginate; servers that do
     * answer with `result.nextCursor`, and a client that ignores it caches the
     * FIRST page as the entire truth — tools silently missing from every
     * later dispatch, with the panel cheerfully counting what it never saw.
     * Each follow-up page goes through the SAME gate as the first: an error
     * reply mid-walk is as fatal as one at the front, because a partial
     * roster cached as a whole one is the defect this method exists to close.
     * A cursor that repeats is a server lying about its pagination — refusing
     * it turns a would-be loop into a start failure with the cursor in the
     * sentence. Page 1's messages stay byte-identical to the pre-ISS-093(b)
     * gate every existing pin asserts; later pages append their page number.
     *
     * @param array<mixed>|null $data the first page, as {@see exchange()} answered
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

        // MAX pages spent and the last reply still handed out a cursor: a
        // roster KNOWN to be cut short must not masquerade as a whole one —
        // missing tools are invisible downstream, exactly the failure this
        // walk exists to prevent. Fail the start the way an error reply does.
        throw new \RuntimeException('tools/list still had a nextCursor after '
            . self::MAX_TOOL_PAGES . ' pages — refusing to cache a truncated roster');
    }

    /**
     * The one gate every `tools/list` page passes, at any page number.
     *
     * A non-JSON / non-object body (e.g. an HTTP 5xx error page) means the
     * leg failed — surface it as a start failure. An ERROR reply (session- or
     * capability-gated servers answer -32601 here) or a reply with no result
     * is not "up, 0 tools" — that shape looks connected, exposes nothing, and
     * throws the server's own diagnosis away. sugar-mcp-client's
     * StdioMcpServer::start() refuses it the same way.
     *
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
     * End the session. A server that issued an `Mcp-Session-Id` is told so
     * with an HTTP DELETE carrying it (the spec's explicit termination);
     * that is best-effort — a 405 (server does not allow client-side
     * termination), a network error or a dead host must not fail a shutdown.
     * A server that issued no session id has nothing to tear down and gets
     * no request at all.
     */
    public function stop(): void
    {
        $this->endSession();
        $this->initialized = false;
    }

    /**
     * Send the session DELETE (see {@see stop()}) when this process owns a
     * session, then forget the session locally either way. Best-effort: a
     * refusal, a network error or a dead host is swallowed, so neither a
     * shutdown nor a failed {@see start()}'s own diagnosis is replaced by it.
     */
    private function endSession(): void
    {
        // A forked process shares the owner's session: ending it here would cut
        // the session out from under the parent and every sibling. Forget it
        // locally instead.
        if ($this->sessionId !== null && $this->ids->isOwner()) {
            try {
                $this->httpClient->delete($this->url, [
                    'headers' => $this->wireHeaders(),
                    // MCP-4 (audit re-run F-1): the bearer rides this request
                    // too — a redirected DELETE must not carry it off-origin.
                    'allow_redirects' => false,
                    'http_errors' => false,
                    'timeout' => self::DELETE_TIMEOUT_SECONDS,
                ]);
            } catch (\Exception) {
                // Best-effort by contract — see the docblock.
            }
        }

        $this->sessionId = null;
        $this->negotiatedVersion = null;
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
            ], $this->toolOptions());

            if ($data === null) {
                return ['error' => 'Invalid response'];
            }

            // ISS-093(c): a JSON-RPC ERROR reply is an answer, and its own
            // code and message were being replaced by the bare string
            // "Tool call failed" — the `??` below once swallowed `$data`
            // whole without ever looking at `error`. That threw away the
            // server's diagnosis (which tool refused, why, with what detail)
            // on the one path where the server had actually replied, leaving
            // the operator to guess. The bridge reads `['error' => …]` as a
            // failure either way ({@see \SugarCraft\Code\Tools\McpToolBridge::isError()});
            // only the TEXT changes, and it changes from a guess to the
            // server's own words. `describeError` is the same formatter
            // start() already uses for its refusals. NO DEADLINE was added on
            // the claude-mcp side of the report: that no-bound is deliberate
            // policy (sugar_issues.md appendix, claude-mcp entry), and only
            // the dropped error text stands.
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
     * MCP-1 wire half. Same gates as the tools/list walk, same per-call
     * ceiling as callTool (`toolOptions()` — E646 keeps the bound per-request,
     * never blanket), on the session-recovering `request()` leg because these
     * calls arrive long after the handshake, when the session may have
     * expired.
     *
     * @return list<array<mixed>> raw `resources` rows — see RAW BYTES LAW on
     *         {@see McpResourceProvider}
     */
    public function listResources(): array
    {
        return $this->collectProviderPages('resources/list', 'resources');
    }

    /**
     * @return array<mixed> the raw `result` of the `resources/read` reply
     */
    public function readResource(string $uri): array
    {
        return $this->requireProviderResult(
            'resources/read',
            $this->request('resources/read', ['uri' => $uri], $this->toolOptions()),
        );
    }

    /**
     * MCP-2 wire half: the prompts roster rides the identical walk and gates
     * as the resources roster — the capability is the same paginated shape
     * under different member names.
     *
     * @return list<array<mixed>> raw `prompts` rows
     */
    public function listPrompts(): array
    {
        return $this->collectProviderPages('prompts/list', 'prompts');
    }

    /**
     * @param array<string,mixed> $arguments
     * @return array<mixed> the raw `result` of the reply
     */
    public function getPrompt(string $name, array $arguments = []): array
    {
        // The empty-arguments object law already enforced for `tools/call`:
        // SDK servers reject `"arguments": []`, and only the empty PHP array
        // reaches json_encode as an array — a populated map encodes as an
        // object on its own.
        return $this->requireProviderResult(
            'prompts/get',
            $this->request(
                'prompts/get',
                ['name' => $name, 'arguments' => $arguments === [] ? new \stdClass() : $arguments],
                $this->toolOptions(),
            ),
        );
    }

    /**
     * Walk a paginated provider list to its end — the ISS-093(b) law applied
     * to the resources/prompts rosters, with the same three refusals
     * {@see collectToolPages()} makes (mid-walk error, repeating cursor,
     * cursor still issued at the page wall). Unlike tools/list this walk is
     * per-call, never cached: a resource roster changes while the server
     * runs, and the launch freeze is a TOOL-surface law.
     *
     * @return list<array<mixed>>
     */
    private function collectProviderPages(string $method, string $key): array
    {
        $entries = [];
        $seenCursors = [];
        $params = null;

        for ($page = 1; $page <= self::MAX_TOOL_PAGES; $page++) {
            $result = $this->requireProviderResult(
                $method,
                $this->request($method, $params, $this->toolOptions()),
            );

            $rows = $result[$key] ?? [];

            if (!is_array($rows)) {
                $rows = [];
            }

            foreach ($rows as $row) {
                if (is_array($row)) {
                    $entries[] = $row;
                }
            }

            $cursor = $result['nextCursor'] ?? null;

            if (!is_string($cursor) || $cursor === '') {
                return $entries;
            }

            if (isset($seenCursors[$cursor])) {
                throw new \RuntimeException(
                    $method . ' repeated cursor ' . $cursor . ' at page ' . $page
                    . ' — refusing to walk a pagination loop',
                );
            }
            $seenCursors[$cursor] = true;
            $params = ['cursor' => $cursor];
        }

        throw new \RuntimeException($method . ' still had a nextCursor after '
            . self::MAX_TOOL_PAGES . ' pages — refusing to return a truncated roster');
    }

    /**
     * The one gate every provider page and every read/get answer passes, in
     * the wording family {@see requireToolsListPage()} established for the
     * tools roster.
     *
     * @param array<mixed>|null $data
     * @return array<mixed>
     */
    private function requireProviderResult(string $method, ?array $data): array
    {
        if ($data === null) {
            throw new \RuntimeException($method . ' returned an invalid response');
        }

        if (isset($data['error'])) {
            throw new \RuntimeException($method . ' refused' . self::describeError($data['error']));
        }

        if (!array_key_exists('result', $data)) {
            throw new \RuntimeException($method . ' returned no result');
        }

        $result = $data['result'];

        if (!is_array($result)) {
            throw new \RuntimeException($method . ' returned a malformed result');
        }

        return $result;
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
            'clientInfo' => ['name' => 'candy-code', 'version' => '1.0.0'],
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
     * @param array<string, mixed> $options per-request Guzzle options (ISS-042:
     *        the `tools/call` ceiling rides this; every other leg keeps the
     *        shared client's own bound)
     * @return array<mixed>|null
     */
    private function request(string $method, ?array $params, array $options = []): ?array
    {
        $carriedSession = $this->sessionId !== null;
        [$id, $response] = $this->dispatch($method, $params, $options);

        if ($carriedSession && $response->getStatusCode() === 404) {
            $this->closeBody($response);
            try {
                $this->handshake();
            } catch (\Exception $e) {
                throw new \RuntimeException("MCP session expired and re-initialize failed: {$e->getMessage()}", 0, $e);
            }
            [$id, $response] = $this->dispatch($method, $params, $options);
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
     * ISS-042: when a bounded request's clock runs out, Guzzle raises from
     * INSIDE `send()` and the id minted below is the only record of what was
     * in flight — so the abandonment is caught HERE, where the id still is,
     * and the spec's `notifications/cancelled` is delivered before the
     * failure propagates. Without this leg the server keeps burning a model
     * call or a build for a client that has already walked away.
     *
     * @param array<string, mixed>|null $params
     * @param array<string, mixed> $options
     * @return array{0: int|string, 1: ResponseInterface}
     */
    private function dispatch(string $method, ?array $params, array $options = []): array
    {
        $id = $this->nextRequestId();
        $payload = ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method];
        if ($params !== null) {
            $payload['params'] = $params;
        }

        try {
            $response = $this->send($payload, $options);
        } catch (TransferException $e) {
            // Every transport failure is an abandonment: the request left this
            // process and no answer came back. A connect refused or a read
            // timeout both leave a server that MAY still be holding work (the
            // timeout especially), which is exactly when the spec's cancel
            // notification earns its keep; the notice is best-effort, so
            // sending it at a dead host costs its 2 s and nothing more.
            // ConnectException::getHandlerContext() exposes the curl handler
            // context; its `timeout` member is set exactly when the easy
            // handle reported a deadline (8.x semantics).
            $timedOut = ($e->getHandlerContext()['timeout'] ?? false) !== false;
            $this->sendCancelled($id, $timedOut
                ? 'the client abandoned the request at its configured timeout'
                : 'the client could not complete the request');

            throw new \RuntimeException(sprintf(
                'MCP call to %s was abandoned: %s (%s)',
                $method,
                $timedOut
                    ? sprintf('no reply within %s seconds', rtrim(rtrim(number_format($this->toolTimeoutSeconds, 3, '.', ''), '0'), '.'))
                    : 'the request never reached the server',
                $e->getMessage(),
            ), 0, $e);
        }

        if ($method === 'initialize') {
            $sessionId = trim($response->getHeaderLine('Mcp-Session-Id'));
            $this->sessionId = $sessionId !== '' ? $sessionId : null;
        }

        return [$id, $response];
    }

    /**
     * The spec's cancellation notification for request $id (ISS-042). A
     * NOTIFICATION — no id, no reply awaited — and best-effort twice over:
     * its own delivery gets a short bound, and a failure here is discarded,
     * because the call already failed and this message must not replace its
     * diagnosis. Mirrors the stdio library's 2-second cancel leg.
     *
     * @param \Throwable|string $reason
     */
    private function sendCancelled(int|string $id, \Throwable|string $reason): void
    {
        $message = $reason instanceof \Throwable ? $reason->getMessage() : $reason;

        try {
            $this->send(['jsonrpc' => '2.0', 'method' => 'notifications/cancelled', 'params' => [
                'requestId' => $id,
                'reason' => $message,
            ]], [
                'timeout' => self::CANCEL_NOTICE_TIMEOUT_SECONDS,
                'connect_timeout' => self::CANCEL_NOTICE_TIMEOUT_SECONDS,
            ]);
        } catch (\Throwable) {
            // Best-effort by contract — see the docblock.
        }
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
     * @param array<string, mixed> $options per-request Guzzle overrides, merged
     *        OVER the shared client's defaults (ISS-042 — the base client keeps
     *        its 30 s; the bounded call leg raises it to the entry's
     *        `toolTimeout` and gives every leg the short connect bound)
     */
    private function send(array $payload, array $options = []): ResponseInterface
    {
        return $this->httpClient->post($this->url, $this->connectionOptions() + [
            'json' => $payload,
            'headers' => $this->wireHeaders(),
            // MCP-4 (audit re-run F-1): never follow a redirect. Every POST here
            // carries the bearer and the whole JSON-RPC body; Guzzle's default
            // policy would re-send both to whatever host the Location header
            // names (up to ten cross-host hops, re-POSTing on 307/308). The
            // refusal is assertSuccess()'s, mirroring the GET gate this transport
            // family already had for the event stream.
            'allow_redirects' => false,
            // Statuses are this class's to interpret: a 404 on a session-bearing
            // request is the expiry signal, not a generic failure.
            'http_errors' => false,
            // ISS-042: the connection phase gets its own short bound so a dead
            // host costs seconds, not the call budget. A per-request option
            // overrides the client default in Guzzle, so the caller's
            // `timeout` above still governs the whole request.
            'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
            // NOT 'stream' => true: Guzzle serves streamed bodies through PHP's
            // http wrapper, whose dechunking filter blocks a read until its
            // buffer fills or the socket closes — MEASURED against
            // server-everything, every SSE reply then waited out Node's 5s
            // keep-alive. The buffered curl path returns when the reply ends,
            // and the spec has the server close an SSE reply stream once the
            // response is sent (both reference SDKs do).
        ] + $options);
    }

    /**
     * The `tools/call` leg's options (ISS-042): the entry's ceiling, resolved
     * once in the constructor. Kept behind a method so the one bounded call
     * site reads as the ONLY consumer.
     *
     * @return array<string, mixed>
     */
    private function toolOptions(): array
    {
        return ['timeout' => $this->toolTimeoutSeconds];
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

        return array_is_list($data) ? SseLineParser::matchReply($data, $id) : $data;
    }

    /**
     * Read SSE events until the one carrying the response to $id (the body is
     * already buffered — see {@see send()} — so this is a parse, not a wait).
     *
     * The framing and matching rules are the shared {@see SseLineParser}'s.
     *
     * @return array<mixed>
     */
    private function replyFromEventStream(StreamInterface $body, int|string $id): array
    {
        // MCP-4: the framing, the line/field state machine and the reply
        // matcher moved VERBATIM into {@see SseLineParser} so the legacy SSE
        // transport parses with the same code. The reader closure is the old
        // loop's read expression — buffered body, 8192-byte slices, `''` at
        // eof — and this class keeps the two decisions that are its own: the
        // body is closed on every exit, and a stream that ended without the
        // reply is the failure this method names.
        try {
            $parser = new SseLineParser(static function () use ($body): string {
                return $body->eof() ? '' : $body->read(8192);
            });
            $reply = $parser->replyTo($id);
        } finally {
            $body->close();
        }

        if ($reply === null) {
            throw new \RuntimeException("event stream ended without a response to request {$id}");
        }

        return $reply;
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

        // MCP-4 (audit re-run F-1): a 3xx is not a diagnosis to relay — it is
        // an egress attempt. send() refused to follow it (allow_redirects), so
        // the hop surfaces here and is refused BY NAME, mirroring the
        // event-stream GET gate in SseMcpServer::assertStreamHead().
        if ($status >= 300 && $status < 400) {
            throw new \RuntimeException(trim("HTTP {$status} {$response->getReasonPhrase()}")
                . ': the JSON-RPC POST was redirected, and this transport does not follow redirects'
                . ' (a redirect could carry the bearer and the request body off the configured origin)');
        }

        $decoded = json_decode($body, true);
        $detail = is_array($decoded) && isset($decoded['error'])
            ? self::describeError($decoded['error'])
            : ($body === '' ? '' : ': ' . mb_strimwidth(trim($body), 0, 200, '…'));

        // ISS-042: 401/403 are not an ordinary transport failure — they are
        // the server ASKING FOR CREDENTIALS, and the remediation is a command
        // the operator already has (`candy-code mcp auth login`). Throwing the
        // typed refusal here, at the one place the status is known, lets
        // start()'s catch rethrow it past the silent-skip guard while every
        // other status keeps the old generic message byte-for-byte.
        if ($status === 401 || $status === 403) {
            throw new HttpMcpAuthRequiredException($this->name, $status);
        }

        throw new \RuntimeException(trim("HTTP {$status} {$response->getReasonPhrase()}") . $detail);
    }

    /**
     * `" (code): message"` for a JSON-RPC error member, whatever its shape.
     *
     * Encoded with JSON_PARTIAL_OUTPUT_ON_ERROR: a decoded error may carry INF
     * (a `1e999` on the wire), and plain json_encode() would answer false —
     * a blank diagnosis for exactly the reply that most needs one.
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
     * OPEN A ROUND LONGER THAN ITS TWIN. {@see \SugarCraft\McpClient\StdioMcpServer::parseTools()} was
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

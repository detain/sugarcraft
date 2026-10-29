<?php

declare(strict_types=1);

namespace SugarCraft\Code\MCP;

/**
 * The SSE wire format as one resumable parser: bytes in through a reader
 * closure, whole events out, and the JSON-RPC reply to a given request id
 * found by {@see replyTo()}.
 *
 * MCP-4 (audit plan_comparison A4) hoisted this out of `HttpMcpServer`
 * verbatim — the framing loop, the line/field state machine and the reply
 * matcher — so the legacy SSE transport ({@see SseMcpServer}) and Streamable
 * HTTP's `text/event-stream` replies share ONE parser instead of minting a
 * second copy that drifts. The extraction is byte-behavioural: the framing
 * rules below carry the original's measurements and reasons, and
 * `HttpMcpServer`'s existing tests pass against this class untouched.
 *
 * TWO CONSTRUCTION MODES (C-MCP-3.c). Closure mode — the original — is fed
 * by the caller: the http transport hands it a Guzzle `StreamInterface`
 * reply body, buffered whole. Stream mode — {@see openEventStream()} — owns
 * the persistent inbound connection itself: the deadline-bounded raw-socket
 * reader that used to live inside {@see SseMcpServer} moved here VERBATIM
 * (same refusals, same chunk-decode, same hostile-flood bound) so the legacy
 * session stream and the opt-in Streamable HTTP GET channel run the exact
 * same wire machinery instead of minting a second copy that drifts.
 *
 * THE READER CONTRACT (why a closure and not a stream): both modes are
 * `fn(): string` returning `''` exactly at end-of-stream; a reader that
 * cannot deliver within its own bound throws, and the exception propagates
 * through this class untouched — a parser that swallowed deadline failures
 * would report "stream ended" for a stream that merely paused. In stream
 * mode the bound is the wait the transport opens with {@see beginWait()}.
 */
final class SseLineParser
{
    /** Head gate: a response head past this is not an SSE head. */
    private const MAX_HEAD_BYTES = 65536;

    /** Framing-line gate: chunk sizes and trailers never legitimately run past it. */
    private const MAX_LINE_BYTES = 8192;

    /** Hostile-flood bound per wait, measured in bytes handed to the framing loop. */
    private const MAX_STREAM_BYTES_PER_WAIT = 8388608;

    /** The open-window bound: writing the GET and reading its head. */
    private const STREAM_TIMEOUT_SECONDS = 10.0;

    /** The media type the persistent GET is opened with and demanded back. */
    public const STREAM_ACCEPT = 'text/event-stream';

    private string $buffer = '';

    /** @var array{event: string, data: list<string>} */
    private array $state = ['event' => '', 'data' => []];

    private bool $atEnd = false;

    private bool $finalDispatched = false;

    // ------------------------------------------------------------------
    // Stream mode state (closure mode leaves every one of these untouched)
    // ------------------------------------------------------------------

    /** Server name, interpolated into the reader refusals exactly as the transport that moved here phrased them. */
    private string $name = '';

    /** @var resource|null the persistent GET's socket, owned while set */
    private $socket = null;

    /** Wait bookkeeping: the reader bounds every read by this deadline and
     *  names this label in the refusal it raises when the deadline passes. */
    private ?float $waitDeadlineAt = null;

    private string $waitLabel = '';

    /** Bytes handed to the framing loop since the current wait began (hostile-flood bound). */
    private int $fedBytesInWait = 0;

    /** Chunked-decode state (transfer-encoding: chunked is what real servers send). */
    private bool $chunked = false;

    private string $payloadBuf = '';

    private ?int $chunkRemaining = null;

    private bool $lastChunkSeen = false;

    /**
     * @param \Closure(): string|null $read next chunk of wire bytes, `''` at
     *        end of stream; null switches the parser to stream mode, where it
     *        reads its own socket — only {@see openEventStream()} passes null.
     */
    public function __construct(private readonly ?\Closure $read = null)
    {
    }

    /**
     * Open the persistent inbound event stream over a raw socket: connect,
     * write the GET, read and gate the response head, and hand back a parser
     * already positioned at the first event byte. The bound is per-phase,
     * never an overall timeout on the connection — each later read rides the
     * wait the transport opens ({@see beginWait()}).
     *
     * The refusal wording, the head gates and the fork-safety of the handle
     * (the CALLER decides fclose-vs-retain from {@see detachSocket()}) are
     * the legacy transport's, moved here byte-for-byte by C-MCP-3.c.
     *
     * @param array<string,string> $headers wire headers for the GET request
     *        line loop (the transport supplies its filtered/authed set)
     * @param \Closure(string, string, int, float): resource|null $connector
     *        @internal test seam replacing the socket connect only
     */
    public static function openEventStream(
        string $url,
        string $name,
        array $headers,
        ?\Closure $connector = null,
        float $connectTimeout = self::STREAM_TIMEOUT_SECONDS,
    ): self {
        $parts = self::parseUrl($url, $name, 'configured URL');
        $connector ??= static fn (string $scheme, string $host, int $port, float $timeoutSeconds) => self::connectStream($scheme, $host, $port, $timeoutSeconds);
        /** @var resource $socket */
        $socket = $connector($parts['scheme'], $parts['host'], $parts['port'], $connectTimeout);

        $parser = new self();
        $parser->name = $name;
        $parser->socket = $socket;

        try {
            $target = ($parts['path'] ?? '') !== '' ? $parts['path'] : '/';
            if (isset($parts['query']) && $parts['query'] !== '') {
                $target .= '?' . $parts['query'];
            }

            $request = "GET {$target} HTTP/1.1\r\n"
                . 'Host: ' . $parts['host'] . ':' . $parts['port'] . "\r\n"
                . "Accept: " . self::STREAM_ACCEPT . "\r\n"
                . "Cache-Control: no-store\r\n"
                . "Connection: keep-alive\r\n";
            foreach ($headers as $header => $value) {
                $request .= "{$header}: {$value}\r\n";
            }
            $request .= "\r\n";

            $parser->writeRequest($request);

            [$status, $reason, $head] = $parser->readHead();
            $parser->assertStreamHead($status, $reason, $head);

            $parser->chunked = str_contains(strtolower($head['transfer-encoding'] ?? ''), 'chunked');
        } catch (\Throwable $e) {
            @fclose($socket);
            $parser->socket = null;

            throw $e;
        }

        return $parser;
    }

    /**
     * The socket behind a stream-mode parser, or null once detached.
     *
     * @return resource|null
     */
    public function socketHandle()
    {
        return $this->socket;
    }

    /**
     * Hand the socket to the caller and forget it — the transport then owns
     * the close-vs-retain decision (a forked child never closes the handle
     * its parent opened; see the retain law on {@see SseMcpServer}).
     *
     * @return resource|null
     */
    public function detachSocket()
    {
        $socket = $this->socket;
        $this->socket = null;

        return $socket;
    }

    /**
     * Open a bounded wait: every read until {@see endWait()} carries this
     * deadline and names this label in its timeout refusal. The flood bound
     * restarts per wait, as it did on the transport this reader moved from.
     */
    public function beginWait(float $seconds, string $label): void
    {
        $this->waitDeadlineAt = microtime(true) + $seconds;
        $this->waitLabel = $label;
        $this->fedBytesInWait = 0;
    }

    public function endWait(): void
    {
        $this->waitDeadlineAt = null;
        $this->waitLabel = '';
    }

    /**
     * Parse and validate an http(s) URL into connectable parts. Lifted from
     * the legacy transport verbatim; the transport's origin-law merges call
     * it too, so the refusal wording exists in exactly one place.
     *
     * @return array{scheme: string, host: string, port: int, path?: string, query?: string}
     */
    public static function parseUrl(string $url, string $name, string $what): array
    {
        $parts = parse_url($url);
        $scheme = is_array($parts) && isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : '';
        $host = is_array($parts) && isset($parts['host']) ? strtolower((string) $parts['host']) : '';

        if (($scheme !== 'http' && $scheme !== 'https') || $host === '') {
            throw new \RuntimeException(
                'MCP server ' . $name . ": {$what} \"{$url}\" is not an http(s) URL with a host",
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
     * The reverse half of the origin merge: parts back into an absolute URL.
     *
     * @param array{scheme: string, host: string, port: int, path?: string, query?: string} $parts
     */
    public static function composeUrl(array $parts): string
    {
        $url = $parts['scheme'] . '://' . $parts['host'] . ':' . $parts['port'] . ($parts['path'] ?? '/');
        if (isset($parts['query']) && $parts['query'] !== '') {
            $url .= '?' . $parts['query'];
        }

        return $url;
    }

    /**
     * The next complete SSE event: an event ends at its blank line, and one
     * left unterminated by end-of-stream is still examined — a server that
     * closes right after its last data line has said what it meant to.
     *
     * Lines end in CRLF, LF or CR; `:` lines are comments/keep-alives;
     * `data:` lines accumulate and join with "\n"; one leading space after
     * the colon is dropped; a field with no colon is the field with an empty
     * value. Events whose `data` never arrived are dispatches with nothing to
     * say and are not returned; `id:` and unknown fields are ignored, matching
     * what this port ever accepted.
     *
     * @return array{event: string, data: string}|null null at end of stream
     */
    public function nextEvent(): ?array
    {
        while (true) {
            $event = $this->dispatchCompleteLines();
            if ($event !== null) {
                return $event;
            }

            if ($this->atEnd) {
                // The buffer now holds only an unterminated tail line, if any:
                // feed it (its fields join the pending state), then dispatch.
                if ($this->buffer !== '') {
                    $line = $this->buffer;
                    $this->buffer = '';
                    $this->consumeLine($line);
                }

                return $this->finalFlush();
            }

            $chunk = $this->read !== null ? ($this->read)() : $this->readStreamChunk();
            if ($chunk === '') {
                $this->atEnd = true;
                continue;
            }
            $this->buffer .= $chunk;
        }
    }

    /**
     * Read events until the JSON-RPC response to request $id. Only `message`
     * events (the default type) carry JSON-RPC; the stream may interleave
     * server notifications and server-to-client requests before the response,
     * and those are skipped. A batch inside one event is matched entry-wise.
     *
     * C-MCP-3.c adds $onFrame: a transport that carries an answer desk passes
     * a router here so the server-initiated traffic it skips is ANSWERED
     * (notifications/progress rides to the wait sink) instead of merely
     * passed over. It is invoked with each decoded non-reply message (a batch
     * rides as the decoded list) and must not throw into the reply walk —
     * best-effort is the router's own contract. Without it the walk is
     * exactly what it always was.
     *
     * @param \Closure(mixed): void|null $onFrame
     * @return array<mixed>|null null when the stream ended with no matching reply
     */
    public function replyTo(int|string $id, ?\Closure $onFrame = null): ?array
    {
        while (true) {
            $event = $this->nextEvent();
            if ($event === null) {
                return null;
            }

            if ($event['event'] !== '' && $event['event'] !== 'message') {
                continue;
            }

            $decoded = json_decode($event['data'], true);
            if (!is_array($decoded)) {
                continue;
            }

            $reply = self::matchReply(array_is_list($decoded) ? $decoded : [$decoded], $id);
            if ($reply !== null) {
                return $reply;
            }

            if ($onFrame !== null) {
                $onFrame($decoded);
            }
        }
    }

    /**
     * The response to $id among JSON-RPC messages: an entry with our id and a
     * `result` or `error`, and no `method` (a server-to-client request may
     * reuse an id value from its own sequence). Ids compare as STRINGS — a
     * forked process mints `pid`-tagged string ids while the owner mints
     * integers, and a server that echoes the number back must still match.
     *
     * @param list<mixed> $messages
     * @return array<mixed>|null
     */
    public static function matchReply(array $messages, int|string $id): ?array
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

    /**
     * Consume every fully terminated line currently in the buffer.
     *
     * @return array{event: string, data: string}|null
     */
    private function dispatchCompleteLines(): ?array
    {
        while (preg_match('/\r\n|\n|\r/', $this->buffer, $m, PREG_OFFSET_CAPTURE) === 1) {
            [$terminator, $offset] = $m[0];
            // A lone CR at the very end may be the first half of a CRLF split
            // across reads — wait for the next chunk to decide. Once the
            // stream has ended there is no next chunk, and the CR is a
            // terminator on its own.
            if ($terminator === "\r" && $offset === strlen($this->buffer) - 1 && !$this->atEnd) {
                return null;
            }
            $line = substr($this->buffer, 0, $offset);
            $this->buffer = (string) substr($this->buffer, $offset + strlen($terminator));

            $event = $this->consumeLine($line);
            if ($event !== null) {
                return $event;
            }
        }

        return null;
    }

    /**
     * Feed one SSE line into the event being assembled; return the completed
     * event when a blank line dispatches one that carries data.
     *
     * @return array{event: string, data: string}|null
     */
    private function consumeLine(string $line): ?array
    {
        if ($line === '') {
            $event = $this->state['event'];
            $data = $this->state['data'];
            $this->state = ['event' => '', 'data' => []];

            if ($data === []) {
                return null;
            }

            return ['event' => $event, 'data' => implode("\n", $data)];
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
            $this->state['data'][] = $value;
        } elseif ($field === 'event') {
            $this->state['event'] = $value;
        }

        return null;
    }

    /**
     * The end-of-stream dispatch of a pending event, ONCE. Subsequent calls
     * answer null forever — end of stream is sticky.
     *
     * @return array{event: string, data: string}|null
     */
    private function finalFlush(): ?array
    {
        if ($this->finalDispatched) {
            return null;
        }
        $this->finalDispatched = true;

        return $this->consumeLine('');
    }

    // ------------------------------------------------------------------
    // Stream mode reader (moved verbatim from SseMcpServer by C-MCP-3.c)
    // ------------------------------------------------------------------

    /**
     * The reader the framing loop pulls through: decoded SSE bytes, `''` at
     * end of stream, every wait bounded by {@see $waitDeadlineAt}. Public
     * only for the @internal deadline seam the transport re-exports.
     */
    public function readStreamChunk(): string
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
        $this->beginWait(self::STREAM_TIMEOUT_SECONDS, 'the response head');
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
        if ($mediaType !== self::STREAM_ACCEPT) {
            throw new \RuntimeException(
                'the event-stream GET was answered with content-type "' . $mediaType . '", not '
                . self::STREAM_ACCEPT,
            );
        }
    }

    private function writeRequest(string $request): void
    {
        $socket = $this->requireSocket();
        $deadlineAt = microtime(true) + self::STREAM_TIMEOUT_SECONDS;

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
            $remaining = ($this->waitDeadlineAt ?? microtime(true) + self::STREAM_TIMEOUT_SECONDS) - microtime(true);
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

    /**
     * The default connector. TLS via php streams with peer verification and
     * SNI on — no option here is a relaxation of what Guzzle's curl handler
     * did by default; the transport moved, the trust posture did not. (Proxy
     * configuration is honoured by neither the moved GET nor any prior sse
     * path; documented in docs/MCP.md.)
     *
     * @return resource
     */
    public static function connectStream(string $scheme, string $host, int $port, float $timeoutSeconds)
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
}

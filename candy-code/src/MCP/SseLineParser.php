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
 * THE READER CONTRACT (why a closure and not a stream): the http transport
 * reads a Guzzle `StreamInterface` (the reply body, buffered whole); the sse
 * transport reads a socket with a wait deadline the CALLER owns. Both are
 * `fn(): string` returning `''` exactly at end-of-stream; a reader that
 * cannot deliver within its own bound throws, and the exception propagates
 * through this class untouched — a parser that swallowed deadline failures
 * would report "stream ended" for a stream that merely paused.
 */
final class SseLineParser
{
    private string $buffer = '';

    /** @var array{event: string, data: list<string>} */
    private array $state = ['event' => '', 'data' => []];

    private bool $atEnd = false;

    private bool $finalDispatched = false;

    /**
     * @param \Closure(): string $read next chunk of wire bytes, `''` at end of stream
     */
    public function __construct(private readonly \Closure $read)
    {
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

            $chunk = ($this->read)();
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
     * @return array<mixed>|null null when the stream ended with no matching reply
     */
    public function replyTo(int|string $id): ?array
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
}

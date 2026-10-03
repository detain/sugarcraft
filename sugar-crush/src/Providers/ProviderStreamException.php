<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers;

/**
 * A streamed completion that failed AFTER the HTTP response had already
 * started, so no status code is left to say so (audit 15a A2).
 *
 * WHY THIS EXISTS
 * ---------------
 * OpenAI-compatible servers (SGLang, vLLM, ...) commit to `200 OK` the moment
 * a stream opens. A failure raised after that point - the prompt turned out
 * to exceed the context window, the request was aborted, the scheduler ran
 * out of memory - can no longer change the status line, so it arrives as one
 * more SSE frame:
 *
 *   data: {"error":{"message":"...","code":400}}       (OpenAI / SGLang)
 *   data: {"object":"error","message":"...","code":400} (vLLM top-level shape)
 *
 * followed, usually, by `data: [DONE]`. The stream loops used to read only
 * `choices[0].delta` and the usage frame, so this frame fell through both
 * branches and the turn "succeeded" with whatever text had streamed before
 * it - often none. {@see fromErrorEvent()} is the one place that recognises
 * the frame, so {@see SglangProvider} (which throws this) and
 * {@see CustomProvider} (which reports it as an `isError` response, its
 * house convention) cannot disagree about what an error frame is.
 *
 * WHY THE VERDICT IS A PROPERTY, NOT A STATUS CODE
 * ------------------------------------------------
 * {@see TransientFailure::isTransient()} classifies by HTTP status when a
 * throwable exposes one, but the real status of this response was a 200 -
 * the server's in-band `code` is a different number, and some failures in
 * this family (a stream that simply stops, audit A3) have no code at all. So
 * the class carries an EXPLICIT {@see $transient} verdict decided by the
 * factory that built it, and `isTransient()` returns that verdict for this
 * type instead of guessing. There is deliberately no `getStatusCode()`.
 *
 * Built only through named factories so each failure shape states its own
 * verdict in one place: {@see fromErrorEvent()} derives it from the server's
 * code with the same rule as an HTTP status
 * ({@see TransientFailure::statusIsTransient()}); {@see prematureEnd()}
 * simply passes `true`.
 *
 * WHY IT EXTENDS \RuntimeException
 * --------------------------------
 * Callers around the providers catch `\RuntimeException` broadly, and
 * SglangProvider's other failures are already `\RuntimeException`s with the
 * same `SGLANG request failed: ` prefix - the user sees one family of message.
 */
final class ProviderStreamException extends \RuntimeException
{
    /**
     * Byte ceiling on a server-supplied message, so a runaway echo of the
     * prompt cannot flood Chat. Same figure SglangProvider uses for its HTTP
     * error bodies, which alias this constant.
     */
    public const MESSAGE_DISPLAY_LIMIT = 2000;

    /** Used when the error frame carries no usable message text. */
    public const FALLBACK_MESSAGE = 'The server reported an error mid-stream without a message.';

    /** What {@see prematureEnd()} says, after the provider's prefix. */
    public const PREMATURE_END_MESSAGE = 'The stream ended before the response finished (connection dropped?)';

    /**
     * @param int|null $serverCode the server's in-band error code, when it
     *                             sent a numeric one; null otherwise
     * @param bool     $transient  whether a retry could succeed - read by
     *                             {@see TransientFailure::isTransient()}
     * @param bool     $contextOverflow whether the server said the prompt did
     *                             not fit its context window - read by
     *                             {@see ContextOverflow::matches()}, for the
     *                             same reason $transient is explicit: no
     *                             HTTP status is left to decide it
     */
    private function __construct(
        string $message,
        public readonly ?int $serverCode,
        public readonly bool $transient,
        public readonly bool $contextOverflow = false,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * The failure an SSE error frame describes, or null when `$frame` is not
     * an error frame (an ordinary delta, the usage frame, `[DONE]`'s null).
     *
     * Transient iff the server's code would be transient as an HTTP status
     * (5xx, 408, 429). No code, or a non-numeric one such as OpenAI's
     * `"context_length_exceeded"`, is UNCLASSIFIED and therefore permanent -
     * the allow-list rule {@see TransientFailure} applies everywhere.
     *
     * A context overflow (roadmap 2.7-1a) is the one permanent failure a
     * smaller request fixes, so it is classified HERE, with the frame in hand:
     * the server's message, its string `code`/`type` (OpenAI's
     * `context_length_exceeded` is a code the message need not repeat) and its
     * numeric code go through {@see ContextOverflow::describesOverflow()}.
     *
     * @param string $prefix prepended to the server's message, so a provider
     *                       can keep its established wording
     */
    public static function fromErrorEvent(mixed $frame, string $prefix = ''): ?self
    {
        if (!is_array($frame)) {
            return null;
        }

        $error = $frame['error'] ?? null;
        if (is_array($error)) {
            [$message, $code] = [$error['message'] ?? null, $error['code'] ?? null];
        } elseif (is_string($error)) {
            // `{"error":"..."}` - the bare-string variant some proxies emit.
            [$message, $code] = [$error, $frame['code'] ?? null];
        } elseif (($frame['object'] ?? null) === 'error') {
            [$message, $code] = [$frame['message'] ?? null, $frame['code'] ?? null];
        } else {
            return null;
        }

        $wording = implode("\n", array_filter(
            [$message, $code, is_array($error) ? ($error['type'] ?? null) : ($frame['type'] ?? null)],
            'is_string',
        ));
        $code = self::numericCode($code);

        return new self(
            $prefix . self::displayMessage($message),
            $code,
            $code !== null && TransientFailure::statusIsTransient($code),
            ContextOverflow::describesOverflow($wording, $code),
        );
    }

    /**
     * A stream that reached EOF without the protocol's terminal signal - no
     * `finish_reason` and no `data: [DONE]` on the OpenAI wire, no
     * `message_stop` on Anthropic's, no `finishReason` on Gemini's (audit
     * 15a A3).
     *
     * A proxy idle-timeout, a server restart or a reset connection does not
     * throw on the streaming path: Guzzle's StreamHandler just reports
     * `eof()`, so the half-finished text used to come back as a complete,
     * untruncated answer. Always TRANSIENT - nothing the request said caused
     * the cut, so a retry can produce the whole response; Runtime retries
     * only while nothing has reached the screen and surfaces it otherwise.
     *
     * @param string $prefix prepended to the message, so a provider can keep
     *                       its established wording
     */
    public static function prematureEnd(string $prefix = ''): self
    {
        return new self($prefix . self::PREMATURE_END_MESSAGE, null, true);
    }

    private static function displayMessage(mixed $message): string
    {
        $message = is_string($message) ? trim($message) : '';
        if ($message === '') {
            return self::FALLBACK_MESSAGE;
        }

        if (strlen($message) > self::MESSAGE_DISPLAY_LIMIT) {
            // mb_substr keeps the cut codepoint-safe.
            $message = mb_substr($message, 0, self::MESSAGE_DISPLAY_LIMIT) . ' [truncated]';
        }

        return $message;
    }

    /** Accepts `400` and `"400"`; anything else is "no code". */
    private static function numericCode(mixed $code): ?int
    {
        if (is_int($code)) {
            return $code > 0 ? $code : null;
        }

        if (is_string($code) && ctype_digit($code)) {
            return (int) $code > 0 ? (int) $code : null;
        }

        return null;
    }
}

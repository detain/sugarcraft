<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers;

use GuzzleHttp\Exception\RequestException;

/**
 * Decides whether a failed provider call failed because the request did not
 * fit the model's context window (roadmap 2.7-1a).
 *
 * WHY IT EXISTS
 * -------------
 * A context-length overflow is the one permanent provider failure the CALLER
 * can fix: drop or summarise history and the identical turn fits. Every
 * provider reports it differently — a 400 with OpenAI's
 * `context_length_exceeded`, SGLang's "The input (N tokens) is longer than the
 * model's context length", an SSE error frame inside a 200 stream, Anthropic's
 * "prompt is too long", Gemini's "input token count … exceeds", Bedrock's
 * "Input is too long for requested model", an `isError` response from the
 * providers that report rather than throw — and until this class they all
 * reached the user as one more unclassified, permanent error
 * ({@see ProviderStreamException::fromErrorEvent()} said so outright). This is
 * the sibling of {@see TransientFailure}: that one answers "retry as is?",
 * this one answers "retry smaller?". The recovery itself — prune maximally,
 * summarise, retry once — is {@see \SugarCraft\Crush\Backend\EngineBackend}'s
 * turn loop (roadmap 2.7-1b); this only gives it a verdict to act on.
 *
 * HOW IT DECIDES, AND WHY TEXT IS PART OF IT
 * ------------------------------------------
 * There is no status code that means "too long": 400 is also a malformed
 * body, and an in-stream frame has no status at all. So:
 *
 *  1. An exception or response that carries an explicit verdict decides
 *     it — {@see ProviderStreamException::$contextOverflow},
 *     {@see ProviderResponseException::$contextOverflow} and
 *     {@see CompleteResponse::$errorContextOverflow}, set where the failure
 *     was built.
 *  2. A transient failure is never an overflow. A 429 "tokens per minute"
 *     message mentions tokens and limits; it is a rate limit, and retrying
 *     it smaller would throw history away for nothing.
 *  3. HTTP 413 is an overflow whatever the body says: the request was too
 *     large, and a smaller one is the only fix.
 *  4. HTTP 400 and 422 — and failures with no status — are an overflow when
 *     the provider's own wording matches {@see PATTERNS}. Any other status is
 *     not, whatever its text says.
 *
 * Fail-closed like {@see TransientFailure}: an unrecognised failure is NOT an
 * overflow, because the recovery discards context and must only run when the
 * provider said the context was the problem.
 */
final class ContextOverflow
{
    /**
     * Provider wordings that mean the prompt exceeded the context window,
     * case-insensitive. One row per family measured or documented upstream;
     * a new provider's wording is a new row and a test case.
     *
     * Deliberately absent: "too many tokens" (Bedrock's ThrottlingException
     * says exactly that) and anything about `max_tokens` alone ("max_tokens is
     * too large", "maximum allowed number of output tokens") — the output cap
     * is a parameter error that dropping history cannot fix.
     */
    public const PATTERNS = [
        // OpenAI / Groq / OpenRouter error code; LiteLLM's exception name.
        '/context[ _]?(?:length|window)[ _]?exceeded/i',
        // OpenAI, vLLM, OpenRouter, Mistral: "maximum context length is N".
        '/maximum context (?:length|window)/i',
        // SGLang / Ollama / LM Studio: "is longer than the model's context length".
        '/\b(?:exceeds?|exceeded|exceeding|longer than|too (?:long|large) for)\b.{0,80}\bcontext (?:length|window|size)\b/i',
        '/\bcontext (?:length|window|size)\b.{0,60}\b(?:exceeded|too (?:long|large|small))\b/i',
        // vLLM: "longer than the maximum model length of N".
        '/maximum model length/i',
        // Anthropic (direct, Vertex, Bedrock): "prompt is too long: N tokens > M maximum".
        '/\bprompt is too long\b/i',
        // Bedrock: "Input is too long for requested model."
        '/\binput is too long\b/i',
        // vLLM: "Input prompt (N tokens) is too long and exceeds limit of M".
        '/\bis too long and exceeds\b/i',
        // Gemini: "The input token count (N) exceeds the maximum number of tokens allowed (M)."
        '/\binput token count\b.{0,40}\bexceeds\b/i',
        // SGLang scheduler: "Input length (N tokens) exceeds the maximum allowed length (M tokens)."
        '/\binput length\b.{0,40}\bexceeds the maximum allowed length\b/i',
        // llama.cpp server error type and message.
        '/exceed_context_size_error|exceeds the available context size/i',
        // Anthropic's 413 error type.
        '/\brequest_too_large\b/i',
    ];

    /**
     * Wording that marks a rate or quota limit, which can mention tokens and
     * limits in the same breath as an overflow and is never one.
     */
    public const RATE_LIMIT_PATTERN = '/rate[ _-]?limit|per (?:min(?:ute)?|hour|day)\b|\bTPM\b|\bquota\b/i';

    /**
     * What {@see describe()} puts in front of a provider's own text. It is
     * itself a {@see PATTERNS} match, so a message built with it classifies
     * as an overflow wherever it travels — the user reads what happened, and
     * a provider that reports a failure as an `isError` {@see CompleteResponse}
     * without setting {@see CompleteResponse::$errorContextOverflow} is still
     * classified by its text.
     */
    public const MESSAGE_PREFIX = 'Context window exceeded: ';

    /** HTTP statuses whose body is read for the wording; 413 needs no wording. */
    public const CLIENT_ERROR_STATUSES = [400, 422];

    /**
     * Whether $failure is a context-window overflow.
     *
     * A {@see CompleteResponse} is judged on its explicit
     * {@see CompleteResponse::$errorContextOverflow} verdict when the provider
     * set one, else on its `errorMessage` (the providers that report rather
     * than throw carry the server's text there) — never when it is not an
     * error and never when it was classified transient.
     */
    public static function matches(\Throwable|CompleteResponse $failure): bool
    {
        if ($failure instanceof CompleteResponse) {
            return $failure->isError
                && $failure->errorTransient !== true
                && ($failure->errorContextOverflow ?? self::describesOverflow((string) $failure->errorMessage));
        }

        $status = null;
        $text = [];
        $seen = [];
        for ($link = $failure; $link !== null; $link = $link->getPrevious()) {
            $key = spl_object_id($link);
            if (isset($seen[$key])) {
                break;
            }
            $seen[$key] = true;

            // An explicit verdict is definitive, like a status is for
            // TransientFailure: it was decided where the failure was built.
            if ($link instanceof ProviderStreamException || $link instanceof ProviderResponseException) {
                return $link->contextOverflow;
            }

            $status ??= self::statusCode($link);
            $text[] = $link->getMessage();
            $text[] = self::errorCode($link);
            $text[] = self::responseBody($link);
        }

        if (TransientFailure::isTransient($failure)) {
            return false;
        }

        if ($status === 413) {
            return true;
        }

        if ($status !== null && !in_array($status, self::CLIENT_ERROR_STATUSES, true)) {
            return false;
        }

        return self::describesOverflow(implode("\n", array_filter($text, static fn (?string $t): bool => $t !== null && $t !== '')));
    }

    /**
     * Whether a provider's error text describes a context overflow — the
     * wording half of {@see matches()}, for a failure whose status (if any)
     * was already judged. $code is an in-band status when the text came with
     * one: 413 decides on its own, anything other than 400/422 rules it out.
     */
    public static function describesOverflow(string $text, ?int $code = null): bool
    {
        if ($code === 413) {
            return true;
        }

        if ($code !== null && !in_array($code, self::CLIENT_ERROR_STATUSES, true)) {
            return false;
        }

        if (trim($text) === '' || preg_match(self::RATE_LIMIT_PATTERN, $text) === 1) {
            return false;
        }

        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a decoded provider error OBJECT — Anthropic's
     * `{"type":"invalid_request_error","message":"prompt is too long: …"}`,
     * OpenAI's `{"code":"context_length_exceeded","message":…}` — describes
     * an overflow. The sibling of
     * {@see TransientFailure::anthropicErrorIsTransient()}, for the providers
     * that receive the failure as data inside a 200 rather than as a status.
     * Anything that is not an array is unclassified and therefore false.
     */
    public static function errorObjectOverflows(mixed $error): bool
    {
        if (!is_array($error)) {
            return false;
        }

        $wording = implode("\n", array_filter(
            [$error['type'] ?? null, $error['code'] ?? null, $error['message'] ?? null],
            'is_string',
        ));

        return self::describesOverflow($wording);
    }

    /**
     * $providerText headed by {@see MESSAGE_PREFIX}, for a failure already
     * judged an overflow: the user reads what happened before the provider's
     * wording of it, and the text carries the verdict to whoever classifies
     * the response later. Idempotent.
     */
    public static function describe(string $providerText): string
    {
        $providerText = trim($providerText);

        return str_starts_with($providerText, self::MESSAGE_PREFIX)
            ? $providerText
            : self::MESSAGE_PREFIX . $providerText;
    }

    /** The same three shapes {@see TransientFailure} reads a status from. */
    private static function statusCode(\Throwable $error): ?int
    {
        if ($error instanceof RequestException) {
            return $error->getResponse()?->getStatusCode();
        }

        if (method_exists($error, 'getStatusCode')) {
            $status = $error->getStatusCode();

            return is_int($status) && $status > 0 ? $status : null;
        }

        return null;
    }

    /**
     * openai-php's `ErrorException::getErrorCode()` (`context_length_exceeded`)
     * and the AWS SDK's `getAwsErrorCode()` — a machine code the message may
     * not repeat.
     */
    private static function errorCode(\Throwable $error): ?string
    {
        foreach (['getErrorCode', 'getAwsErrorCode'] as $method) {
            if (method_exists($error, $method)) {
                $code = $error->{$method}();
                if (is_string($code) && $code !== '') {
                    return $code;
                }
            }
        }

        return null;
    }

    /**
     * The full body of a Guzzle error response: its exception message clips
     * the body at 120 bytes, and a proxy's prefix can push the wording past
     * that. Read through a rewind so a body a caller already consumed is
     * still readable; never throws.
     */
    private static function responseBody(\Throwable $error): ?string
    {
        if (!$error instanceof RequestException || !$error->hasResponse()) {
            return null;
        }

        try {
            $body = $error->getResponse()->getBody();
            if ($body->isSeekable()) {
                $body->rewind();
            }

            return $body->read(64 * 1024);
        } catch (\Throwable) {
            return null;
        }
    }
}

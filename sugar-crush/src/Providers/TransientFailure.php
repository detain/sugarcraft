<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers;

use Aws\Exception\AwsException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use OpenAI\Exceptions\TransporterException;
use Psr\Http\Client\NetworkExceptionInterface;

/**
 * Decides whether a failed provider call is worth trying again, and how long
 * to wait before doing so (crush_code.md Phase 5 item 8).
 *
 * WHY THIS IS A CLASSIFIER AND NOT A RETRY LOOP
 * ---------------------------------------------
 * The call sites' rollback obligations genuinely differ — {@see
 * \SugarCraft\Crush\Runtime::runBatch()} has nothing to undo, {@see
 * \SugarCraft\Crush\Runtime::runStreaming()} has four accumulators and an
 * append-only token sink it cannot un-emit, and {@see
 * \SugarCraft\Crush\Agents\AgentManager::executeSubAgent()} mutates a shared
 * {@see \SugarCraft\Crush\Agents\SubAgent} that has already been yielded to a
 * consumer — so a shared `attempt(callable)` wrapper would have to take a
 * reset closure per site AND could not be used at all in AgentManager, whose
 * loop body `yield`s. Four short explicit loops that each name what they roll
 * back are more honest than one wrapper with three escape hatches. What IS
 * shared, and what would otherwise drift out of step between them, is the
 * *policy*: what counts as transient, how many attempts, how long to wait.
 *
 * WHY IT IS NOT WRAPPED AROUND THE AGENTIC LOOP
 * --------------------------------------------
 * crush_code.md Phase 5 item 8 names
 * {@see \SugarCraft\Crush\Backend\EngineBackend::runCompleteInChild()} as the
 * place for this. It is the wrong place and would cause data loss, so it is
 * deliberately not used there. That method calls
 * {@see \SugarCraft\Crush\Backend\EngineBackend::complete()}, which IS the
 * bounded agentic loop — `for ($step = 0; $step < $maxSteps; $step++)` with
 * tool dispatch inside it. Retrying around it re-runs every tool call the
 * failed attempt already executed: a `Bash` that already ran `rm`, an `Edit`
 * that already wrote, a `Write` that already created. That is a replay, not a
 * retry. `runCompleteInChild()` is also only the FORKED path, so a retry there
 * would leave the synchronous `complete()` route and both AgentManager call
 * sites unprotected — the same 5xx recovered or fatal depending on which entry
 * point the user came through.
 *
 * The single provider call is the seam that retries nothing but itself, so
 * that is where the four loops live.
 *
 * WHAT COUNTS AS TRANSIENT, AND WHY IT IS AN ALLOW-LIST
 * ----------------------------------------------------
 * Only shapes positively recognised as retryable return true; everything else
 * — including every exception this class has never heard of — is treated as
 * permanent. Fail-closed matters more than coverage here for two reasons.
 * Retrying an auth failure three times only triples the delay before the user
 * sees the real problem. And the wrapped call sites throw non-transport
 * exceptions through the same seam: a
 * {@see \SugarCraft\Crush\Agents\AgentManager::evaluateToolCalls()} permission
 * denial is raised inside the very `foreach` that reads the stream, and a
 * deny-list would have retried it.
 *
 * Recognised as transient:
 *   - {@see NetworkExceptionInterface} (PSR-18) — DNS/TCP/TLS never completed.
 *     `GuzzleHttp\Exception\ConnectException` implements it.
 *   - HTTP 5xx — the server admitted it failed.
 *   - HTTP 408 and 429 — the only two 4xx codes whose SEMANTICS are "try this
 *     again". They are named individually rather than by range precisely
 *     because the rest of 4xx must not be retried.
 *   - An {@see AwsException} reporting `isConnectionError()`.
 *   - {@see TransporterException} — openai-php only ever raises it wrapping a
 *     PSR-18 client exception, i.e. it is by construction a transport failure.
 *   - A {@see TransferException} carrying no response — a read that died
 *     mid-body ("Unable to read from stream"), which is what a dropped
 *     connection looks like on the stream transport.
 *
 * Explicitly NOT transient: any other 4xx (401/403 auth, 400 malformed,
 * 404, 413/422 context-length overflow), `\InvalidArgumentException` from a
 * provider's own local input validation, and any exception with no
 * classifiable transport information at all.
 *
 * "TIMEOUT" HERE MEANS CLASSIFYING ONE, NEVER IMPOSING ONE
 * -------------------------------------------------------
 * The plan's "network/5xx/timeout only" licenses reading a timeout the
 * provider ALREADY raised as transient. It does not license adding a
 * total-request deadline, which is a standing prohibition in this library —
 * see {@see Concerns\HttpClientDefaults} for why a completion legitimately
 * runs tens of minutes and {@see VertexProvider::callOptions()} for the
 * lengths gone to in order to REMOVE the Google SDK's own 60s one. Nothing
 * here sets a timeout, and nothing here re-enables an SDK's retry layer;
 * this sits strictly above the providers.
 *
 * THE BACKOFF IS BOUNDED BY AN IDLE TIMER, NOT BY TASTE
 * ----------------------------------------------------
 * {@see \SugarCraft\Crush\Backend\EngineBackend}'s 120s
 * `COMPLETE_TIMEOUT_SECONDS` is an *idle* ceiling: it is re-armed on every
 * frame the forked child streams, and no frame is emitted while this class is
 * sleeping. So the sleeps of a whole exhausted retry sequence are silence
 * against that clock and must sum to far less than it, or a retry would get
 * the entire turn SIGKILLed from above — strictly worse than the single
 * failure it was trying to paper over. {@see totalBackoffMicroseconds()}
 * derives that sum so a test can assert the relationship rather than a
 * literal.
 *
 * The sleep is a plain `usleep()` and is NOT interruptible. On the interactive
 * path that is harmless: completion runs inside the forked child (see
 * `runCompleteInChild()`), so the sleep is in the child and the TEA loop keeps
 * turning. On a build without ext-pcntl,
 * `EngineBackend::completeAsyncBlocking()` runs the completion inline and the
 * sleep DOES block the loop's thread — which is why the total is kept to
 * {@see totalBackoffMicroseconds()} rather than the tens of seconds a
 * server-side retry budget would use.
 *
 * DOMAIN OF THAT FIGURE, because it was first written next to the wrong noun:
 * `totalBackoffMicroseconds()` is per PROVIDER CALL, not per turn.
 * `EngineBackend::complete()` makes up to `maxSteps` provider calls in one turn
 * (`EngineBackend::__construct()`, default 8), and a turn that spawns sub-agents
 * adds their retries on top. So the worst case for uninterruptible blocking on
 * the ext-pcntl-less path is `maxSteps x` that sum — about twelve seconds at the
 * current constants and default, not one and a half. The idle-ceiling argument
 * above is unaffected: `COMPLETE_TIMEOUT_SECONDS` is re-armed per frame and each
 * backoff sequence is one uninterrupted silence, so the per-call figure is the
 * right one to compare against it. It is the loop-blocking claim that has to be
 * multiplied out.
 */
final class TransientFailure
{
    /**
     * Total provider calls per seam, including the first — so at the current
     * value one call and up to two retries.
     *
     * The plan asks for 2-3; 3 is the top of that range because the failure
     * this exists for (a single 502 from a load balancer rotating a backend)
     * is usually gone by the second attempt and essentially always by the
     * third, while a fourth mostly adds latency to outages that are not
     * transient at all.
     */
    public const MAX_ATTEMPTS = 3;

    /**
     * Wait after the FIRST failed attempt; each subsequent wait doubles.
     *
     * 500ms is long enough for a rotating upstream to finish rotating and
     * short enough that a recovered turn does not read as a hang. See the
     * class docblock for the idle-timer ceiling this sits under.
     */
    public const BASE_BACKOFF_MICROSECONDS = 500_000;

    /**
     * Whether this thrown failure is worth another attempt.
     *
     * Walks the `getPrevious()` chain because TWO providers wrap the informative
     * exception in a bare one and would otherwise be unclassifiable:
     * {@see SglangProvider} throws `\RuntimeException(..., 0, $guzzleException)`
     * and {@see BedrockProvider} throws `\RuntimeException(..., 0, $awsException)`.
     * The walk is depth-bounded so a self-referential chain cannot spin.
     *
     * {@see TransporterException} is deliberately NOT in that list, though an
     * earlier version of this docblock counted it as the third. It is an
     * openai-php exception class rather than a provider, and it needs no walk:
     * measured, `class_parents()` is `[Exception]`, it has no `getStatusCode()`
     * and it is not a `NetworkExceptionInterface`, so the clause below matches it
     * on the FIRST link. It appears here only because it is a class this method
     * recognises, not because the chain is what recognises it.
     *
     * A recognised HTTP status is DEFINITIVE and stops the walk: once the
     * server has told us it was a 401, an inner transport exception saying
     * "connection reset" would be a lie about the same failure.
     */
    public static function isTransient(\Throwable $error): bool
    {
        $seen = [];

        for ($link = $error; $link !== null; $link = $link->getPrevious()) {
            // Guards a cyclic chain, which is constructible even though no
            // provider here builds one.
            $key = spl_object_id($link);
            if (isset($seen[$key])) {
                break;
            }
            $seen[$key] = true;

            $status = self::statusCode($link);
            if ($status !== null) {
                return self::statusIsTransient($status);
            }

            if ($link instanceof NetworkExceptionInterface) {
                return true;
            }

            if ($link instanceof AwsException && $link->isConnectionError()) {
                return true;
            }

            if ($link instanceof TransporterException) {
                return true;
            }

            // Last, and only with no status in hand: a Guzzle transfer that
            // died without ever producing a response. Checked after the status
            // lookup because ClientException/ServerException are both
            // TransferExceptions and must be judged on their code instead.
            if ($link instanceof TransferException) {
                return true;
            }
        }

        return false;
    }

    /**
     * The Anthropic API error `type` values that mean "try again", for the
     * failures that arrive as a structured error OBJECT rather than as an HTTP
     * status or an exception.
     *
     * COUNTING THIS PRECISELY, because two places in this repo used to give two
     * different numbers for the same taxonomy. Failures reach the retry seams
     * through exactly TWO channels: a thrown `\Throwable`, or a
     * {@see CompleteResponse} with `isError: true`. This constant does not add a
     * third channel — it adds a second thing to CLASSIFY inside the second
     * channel, which is why this class exposes three predicates (a `\Throwable`,
     * a `CompleteResponse`, a decoded error object) over two channels.
     *
     * Without it the retry would miss its single most common real case.
     * Anthropic-on-Vertex streams through `:streamRawPredict`, whose overload signal is not a 503
     * on the HTTP response — the response is a successful 200 SSE stream that
     * contains an `error` event carrying `{"type":"overloaded_error"}`. {@see
     * VertexProvider::parseAnthropicChunk()} already turns that into an error
     * {@see CompleteResponse}; classifying the type is what lets it be retried.
     *
     * `overloaded_error` is Anthropic's capacity signal (the 529 analogue),
     * `rate_limit_error` its 429 and `api_error` its 5xx. Deliberately absent:
     * `invalid_request_error`, `authentication_error`, `permission_error`,
     * `not_found_error` and `request_too_large` — the same permanent classes
     * {@see statusIsTransient()} excludes, for the same reason.
     */
    public const TRANSIENT_ANTHROPIC_ERROR_TYPES = [
        'overloaded_error',
        'rate_limit_error',
        'api_error',
    ];

    /**
     * Whether a decoded Anthropic error object describes a transient failure.
     *
     * Takes the raw decoded value rather than a string so the caller does not
     * have to pre-validate provider JSON: anything that is not an array with a
     * recognised string `type` is UNCLASSIFIED and therefore permanent, which
     * is the allow-list rule again.
     */
    public static function anthropicErrorIsTransient(mixed $error): bool
    {
        if (!is_array($error)) {
            return false;
        }

        $type = $error['type'] ?? null;

        return is_string($type) && in_array($type, self::TRANSIENT_ANTHROPIC_ERROR_TYPES, true);
    }

    /**
     * Whether an error-carrying {@see CompleteResponse} is worth another
     * attempt.
     *
     * {@see CustomProvider} and {@see VertexProvider} report a failed call as
     * `isError: true` rather than by throwing, and until this seam existed they
     * discarded the exception at the catch site and kept only its message — so
     * the only thing left to classify on was prose. They now classify the live
     * exception with {@see isTransient()} and carry the verdict in
     * {@see CompleteResponse::$errorTransient}, which is what this reads.
     *
     * `null` means "nobody classified this" and is NOT retried: an unclassified
     * error response is the same unknown as an unrecognised exception, and the
     * allow-list rule applies to both.
     */
    public static function responseIsTransient(CompleteResponse $response): bool
    {
        return $response->isError && $response->errorTransient === true;
    }

    /**
     * Sleep the backoff owed after `$attempt` failed attempts, where
     * `$attempt` is 1-based.
     *
     * Deliberately deterministic — no jitter. Jitter exists to de-synchronise
     * many clients retrying against one server; this is a single-user terminal
     * app making one completion at a time, so there is no herd to spread, and
     * a fixed schedule is worth more because it can be asserted exactly.
     */
    public static function backoff(int $attempt): void
    {
        $micros = self::backoffMicroseconds($attempt);
        if ($micros > 0) {
            usleep($micros);
        }
    }

    /**
     * The wait owed after `$attempt` failed attempts (1-based), in
     * microseconds. Zero once no attempts remain, so an exhausted sequence
     * does not pay for a retry it will not make.
     */
    public static function backoffMicroseconds(int $attempt): int
    {
        if ($attempt < 1 || $attempt >= self::MAX_ATTEMPTS) {
            return 0;
        }

        return self::BASE_BACKOFF_MICROSECONDS * (2 ** ($attempt - 1));
    }

    /**
     * Every sleep a fully exhausted retry sequence performs, summed.
     *
     * Derived rather than written down, because its whole job is to be
     * compared against `EngineBackend::COMPLETE_TIMEOUT_SECONDS` — the idle
     * ceiling this silence is measured against (see the class docblock) — and
     * a literal here would stop tracking the constants above the first time
     * one of them moved.
     */
    public static function totalBackoffMicroseconds(): int
    {
        $total = 0;
        for ($attempt = 1; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $total += self::backoffMicroseconds($attempt);
        }

        return $total;
    }

    /**
     * The HTTP status a failure carries, or null when it carries none.
     *
     * Three shapes, because the three SDKs in play expose it three ways and
     * none of them shares an interface for it: Guzzle hangs a response off the
     * exception, openai-php's `ErrorException` and the AWS SDK's `AwsException`
     * both answer `getStatusCode()` directly. `method_exists()` rather than a
     * class check on the latter pair so this does not hard-require either SDK's
     * class to be loadable in order to classify the other's exception.
     */
    private static function statusCode(\Throwable $error): ?int
    {
        if ($error instanceof RequestException) {
            $response = $error->getResponse();

            return $response === null ? null : $response->getStatusCode();
        }

        if (method_exists($error, 'getStatusCode')) {
            $status = $error->getStatusCode();

            // AwsException::getStatusCode() is nullable, and a 0 from any of
            // them means "never got a response" rather than a real code.
            return is_int($status) && $status > 0 ? $status : null;
        }

        return null;
    }

    /**
     * 5xx, plus exactly 408 and 429.
     *
     * Named individually rather than as a range because the point of this
     * method is that the REST of 4xx is permanent: 401/403 will not fix
     * themselves, 400 and 422 mean the request is wrong, and a
     * context-length overflow retried three times overflows three times.
     */
    private static function statusIsTransient(int $status): bool
    {
        return $status >= 500 || $status === 408 || $status === 429;
    }
}

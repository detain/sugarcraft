<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers;

use Aws\Exception\AwsException;
use Google\ApiCore\ApiException;
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
 *   - A {@see ProviderStreamException} whose own `$transient` verdict is
 *     true — a failure reported INSIDE a 200 SSE stream, where no HTTP status
 *     exists to classify. Its factory decides the verdict (an in-band error
 *     code goes through {@see statusIsTransient()}, so 5xx/408/429 retry and
 *     a 400 context-length overflow does not; a stream that simply stops
 *     before its terminal signal is always transient, audit A3); this class
 *     only reads it.
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
 * THE SLEEP IS SLICED AGAINST A DEADLINE, BECAUSE `usleep()` IS INTERRUPTIBLE
 * ---------------------------------------------------------------------------
 * This paragraph used to read "the sleep is a plain `usleep()` and is NOT
 * interruptible", and it had the fact backwards. MEASURED on this host, PHP
 * 8.3.6: `usleep(1_000_000)` with a caught signal delivered 200ms in returns
 * after 0.2017s — PHP does not restart the sleep across EINTR. Measured
 * through this class, with `pcntl_async_signals(true)` and the empty SIGWINCH
 * handler {@see \SugarCraft\Core\Program} installs for the whole TUI, and a
 * SIGWINCH raised 120ms into `backoff(1)`: 500000µs owed, 119896µs slept.
 * Three quarters of the backoff gone.
 *
 * Which is not cosmetic. Those handlers are INHERITED ACROSS THE COMPLETION
 * FORK — nothing in `EngineBackend::runCompleteInChild()` resets them — and a
 * terminal resize during a retry storm is precisely the moment a user reaches
 * for the window. A backoff that collapses to nothing puts the retry back on
 * the still-failing upstream immediately, which is the single thing a backoff
 * exists to prevent, and it does it silently.
 *
 * So {@see backoff()} re-sleeps the remainder until its deadline is actually
 * reached. Signals are still delivered PROMPTLY — the handler runs on the
 * EINTR, between slices — so nothing that used to respond quickly responds any
 * slower; what changed is that the wait now lasts as long as it claims to.
 *
 * IT STAYS SYNCHRONOUS, deliberately, and it is not a request deadline. On the
 * interactive path the blocking is harmless: completion runs inside the forked
 * child (see `runCompleteInChild()`), so the sleep is in the child and the TEA
 * loop keeps turning. On a build without ext-pcntl,
 * `EngineBackend::completeAsyncBlocking()` runs the completion inline and the
 * sleep DOES block the loop's thread — which is why the total is kept to
 * {@see totalBackoffMicroseconds()} rather than the tens of seconds a
 * server-side retry budget would use. Handing this to the event loop instead
 * would mean re-entering the provider from a timer callback, i.e. restructuring
 * both retry loops that call this ({@see \SugarCraft\Crush\Runtime} and
 * {@see \SugarCraft\Crush\Agents\AgentManager}); the bound above is what
 * makes that unnecessary.
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
     *
     * Google's gax {@see ApiException} (audit A19) is the one SDK exception
     * whose verdict is NOT an HTTP status. The vendored REST transport
     * (`RestTransport`, `RestServerStreamingCall`) converts every Guzzle
     * failure that HAS a response into one via
     * `ApiException::createFromRequestException()`, which keeps only the
     * gRPC code and status name - no `getStatusCode()`, no `previous` - so
     * before this arm every Vertex 429 `RESOURCE_EXHAUSTED` and 503
     * `UNAVAILABLE` fell off the end of the walk as permanent and was never
     * retried. It is judged by {@see apiExceptionIsTransient()} and, like a
     * status, stops the walk.
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

            // An in-stream failure carries its verdict explicitly, because the
            // only HTTP status in play was the stream's 200 - see that class.
            // Definitive, like a status: it stops the walk.
            if ($link instanceof ProviderStreamException) {
                return $link->transient;
            }

            $status = self::statusCode($link);
            if ($status !== null) {
                return self::statusIsTransient($status);
            }

            // `instanceof` never autoloads, so this costs nothing - and
            // requires nothing - on an install without google/gax.
            if ($link instanceof ApiException) {
                return self::apiExceptionIsTransient($link);
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
     *
     * @param callable(int):void|null $sleeper
     *        Sleep seam (same family as {@see \SugarCraft\Crush\MCP\OAuthLoopbackFlow}'s
     *        clock): the loop calls it with the microseconds owed instead of
     *        `usleep()`. Production passes nothing and behaves exactly as
     *        before; a test can count calls to prove the no-debt path never
     *        sleeps at all, instead of bounding a zero-work return by wall
     *        time a loaded scheduler can violate.
     */
    public static function backoff(int $attempt, ?callable $sleeper = null): void
    {
        $owed = self::backoffMicroseconds($attempt);
        if ($owed <= 0) {
            return;
        }

        $nap = $sleeper !== null
            ? \Closure::fromCallable($sleeper)
            : static function (int $microseconds): void {
                usleep($microseconds);
            };

        // A DEADLINE RATHER THAN ONE `usleep()`, because one `usleep()` is not
        // a wait, it is a wait UNTIL THE NEXT SIGNAL — see the class docblock
        // for the measurement. Re-armed from the clock rather than by summing
        // what was slept, so a handler that itself takes time is paid for out
        // of the backoff instead of being added to it.
        $deadline = microtime(true) + $owed / 1_000_000;
        $micros = $owed;

        while (true) {
            $nap($micros);

            $remaining = $deadline - microtime(true);
            if ($remaining <= 0.0) {
                return;
            }

            // `ceil`, so a remainder under a microsecond still asks for one and
            // the loop cannot become a spin on a value that rounds to zero.
            $micros = (int) ceil($remaining * 1_000_000);
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
     * The gRPC status names that mean "try again" (audit A19).
     *
     * `RESOURCE_EXHAUSTED` is Vertex's quota 429 and `UNAVAILABLE` its
     * overloaded 503 - the two routine failures on a shared project.
     * `DEADLINE_EXCEEDED` is gax's mapping of a 504, `INTERNAL` of every
     * other 5xx (including the bare 500), and `ABORTED` is gRPC's own
     * "retry at a higher level" (gax maps a 409 to it). This mirrors
     * {@see statusIsTransient()}: 5xx/408/429 there, their gRPC names here.
     *
     * `UNKNOWN` is deliberately ABSENT. gax never derives it from an HTTP
     * status - a 5xx becomes `INTERNAL` - so it only appears when a server
     * NAMES it, and "the server could not say what went wrong" is exactly
     * the unclassified case the allow-list rule keeps permanent. Also
     * absent, for the reasons statusIsTransient() gives for 4xx:
     * `UNAUTHENTICATED`, `PERMISSION_DENIED`, `INVALID_ARGUMENT`,
     * `NOT_FOUND`, `FAILED_PRECONDITION`, `OUT_OF_RANGE`, `UNIMPLEMENTED`,
     * `ALREADY_EXISTS`, `CANCELLED`, `DATA_LOSS`.
     */
    public const TRANSIENT_GRPC_STATUSES = [
        'RESOURCE_EXHAUSTED',
        'UNAVAILABLE',
        'DEADLINE_EXCEEDED',
        'INTERNAL',
        'ABORTED',
    ];

    /**
     * The canonical gRPC code table (`google.rpc.Code`), so a status-less
     * ApiException can still be judged by its numeric code without loading
     * `Google\Rpc\Code` - kept local for the same reason {@see statusCode()}
     * uses `method_exists()`: classifying one SDK's exception must not
     * require another SDK to be installed.
     */
    private const GRPC_CODE_NAMES = [
        0 => 'OK',
        1 => 'CANCELLED',
        2 => 'UNKNOWN',
        3 => 'INVALID_ARGUMENT',
        4 => 'DEADLINE_EXCEEDED',
        5 => 'NOT_FOUND',
        6 => 'ALREADY_EXISTS',
        7 => 'PERMISSION_DENIED',
        8 => 'RESOURCE_EXHAUSTED',
        9 => 'FAILED_PRECONDITION',
        10 => 'ABORTED',
        11 => 'OUT_OF_RANGE',
        12 => 'UNIMPLEMENTED',
        13 => 'INTERNAL',
        14 => 'UNAVAILABLE',
        15 => 'DATA_LOSS',
        16 => 'UNAUTHENTICATED',
    ];

    /**
     * Whether a gax ApiException describes a transient failure.
     *
     * Three readings, most specific first, because gax builds the exception
     * three ways:
     *
     *  1. A recognised status NAME (`getStatus()`) - every exception gax
     *     builds from an error body or an RPC status carries one.
     *  2. No recognised name: the numeric code. A gRPC code (0-16) maps
     *     through {@see GRPC_CODE_NAMES}. An HTTP-range code (100-599) is
     *     judged as the HTTP status it is: when an error body has no
     *     `status` member, `createFromRequestException()` passes the Guzzle
     *     exception's code - the HTTP status - straight through, paired with
     *     `UNRECOGNIZED_STATUS`. The two ranges cannot collide.
     *  3. Neither a name nor a code (status null/empty, code 0): the
     *     exception says nothing the server decided, which is the shape of a
     *     transport failure rather than a refusal - so it is treated like
     *     the other network-error arms of {@see isTransient()}, transient.
     *     Bounded by {@see MAX_ATTEMPTS} like every other retry.
     *
     * Anything else (gax's `UNRECOGNIZED_CODE` -1 for a 1xx/3xx) is
     * unclassified, and the allow-list rule makes it permanent.
     */
    private static function apiExceptionIsTransient(ApiException $error): bool
    {
        $status = $error->getStatus();
        if (is_string($status) && in_array($status, self::GRPC_CODE_NAMES, true)) {
            return in_array($status, self::TRANSIENT_GRPC_STATUSES, true);
        }

        $code = $error->getCode();
        if (isset(self::GRPC_CODE_NAMES[$code]) && $code !== 0) {
            return in_array(self::GRPC_CODE_NAMES[$code], self::TRANSIENT_GRPC_STATUSES, true);
        }

        if ($code >= 100 && $code < 600) {
            return self::statusIsTransient($code);
        }

        return $code === 0 && ($status === null || $status === '');
    }

    /**
     * 5xx, plus exactly 408 and 429.
     *
     * Named individually rather than as a range because the point of this
     * method is that the REST of 4xx is permanent: 401/403 will not fix
     * themselves, 400 and 422 mean the request is wrong, and a
     * context-length overflow retried three times overflows three times.
     *
     * Public so {@see ProviderStreamException::fromErrorEvent()} judges a
     * server's in-band error code by this same rule instead of a copy of it.
     */
    public static function statusIsTransient(int $status): bool
    {
        return $status >= 500 || $status === 408 || $status === 429;
    }
}

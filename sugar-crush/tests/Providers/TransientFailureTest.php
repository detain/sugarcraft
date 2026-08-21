<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use Aws\Command;
use Aws\Exception\AwsException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OpenAI\Exceptions\ErrorException;
use OpenAI\Exceptions\TransporterException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\TransientFailure;

/**
 * Classification truth for crush_code.md Phase 5 item 8.
 *
 * Every case here asserts the VERDICT for a failure shape a provider in this
 * library actually produces, not that some clause mentioning it exists. The
 * negative cases matter at least as much as the positive ones: the whole risk
 * of a retry layer is that it retries something permanent and triples the delay
 * before the user sees the real error.
 */
final class TransientFailureTest extends TestCase
{
    private static function request(): Request
    {
        return new Request('POST', 'https://example.invalid/v1/chat/completions');
    }

    // -------------------------------------------------------------------------
    // Network failures
    // -------------------------------------------------------------------------

    public function testAConnectExceptionIsTransient(): void
    {
        // ConnectException implements PSR-18's NetworkExceptionInterface, which
        // is the interface this classifier keys on rather than the Guzzle class.
        $this->assertTrue(TransientFailure::isTransient(
            new ConnectException('cURL error 7: Failed to connect', self::request()),
        ));
    }

    /**
     * The `NetworkExceptionInterface` clause, reached by nothing else.
     *
     * `testAConnectExceptionIsTransient()` above does NOT reach it: measured,
     * `ConnectException extends TransferException`, so it is answered by the
     * response-less-transfer clause at the bottom of the walk instead —
     * replacing `$link instanceof NetworkExceptionInterface` with `if (false)`
     * was green across 2863 tests. A PSR-18 client that is not Guzzle raises a
     * network exception that is not a `TransferException`, and this is the only
     * clause that can classify it.
     *
     * The premise is asserted rather than trusted, because the whole defect this
     * test fixes was a premise nobody checked.
     */
    public function testANonGuzzlePsr18NetworkExceptionIsTransient(): void
    {
        $failure = new PsrNetworkFailure(self::request());

        self::assertNotInstanceOf(TransferException::class, $failure, 'a Guzzle transfer would be caught by another clause');
        self::assertFalse(method_exists($failure, 'getStatusCode'), 'a status would short-circuit the walk');
        self::assertNotInstanceOf(AwsException::class, $failure);
        self::assertNotInstanceOf(TransporterException::class, $failure);
        self::assertInstanceOf(NetworkExceptionInterface::class, $failure);

        $this->assertTrue(TransientFailure::isTransient($failure));
    }

    public function testAGuzzleTransferWithNoResponseIsTransient(): void
    {
        // What a stream dying mid-body looks like on the StreamHandler path.
        $this->assertTrue(TransientFailure::isTransient(
            new TransferException('Unable to read from stream'),
        ));
    }

    // -------------------------------------------------------------------------
    // HTTP statuses. 5xx plus exactly 408 and 429; every other 4xx permanent.
    // -------------------------------------------------------------------------

    /**
     * @dataProvider transientStatuses
     */
    public function testTheseStatusesAreTransient(int $status): void
    {
        $this->assertTrue(
            TransientFailure::isTransient(new ServerException(
                'server said ' . $status,
                self::request(),
                new Response($status),
            )),
            $status . ' must be retried',
        );
    }

    /** @return array<string, array{int}> */
    public static function transientStatuses(): array
    {
        return [
            '500 internal' => [500],
            '502 bad gateway' => [502],
            '503 unavailable' => [503],
            '504 gateway timeout' => [504],
            '529 anthropic overloaded' => [529],
            '408 request timeout' => [408],
            '429 rate limited' => [429],
        ];
    }

    /**
     * The load-bearing negative half. A 401 retried three times is three times
     * the wait before the user learns their key is wrong.
     *
     * @dataProvider permanentStatuses
     */
    public function testTheseStatusesAreNotTransient(int $status): void
    {
        $this->assertFalse(
            TransientFailure::isTransient(new ClientException(
                'server said ' . $status,
                self::request(),
                new Response($status),
            )),
            $status . ' must NOT be retried',
        );
    }

    /** @return array<string, array{int}> */
    public static function permanentStatuses(): array
    {
        return [
            '400 malformed request' => [400],
            '401 bad credentials' => [401],
            '403 forbidden' => [403],
            '404 no such model' => [404],
            '413 payload too large' => [413],
            '422 unprocessable' => [422],
        ];
    }

    /**
     * A status is DEFINITIVE and stops the walk: an inner transport exception
     * must not be able to talk a 401 into being retryable.
     */
    public function testAStatusOutranksATransientCause(): void
    {
        $auth = new ClientException(
            '401 Unauthorized',
            self::request(),
            new Response(401),
            new ConnectException('connection reset', self::request()),
        );

        $this->assertFalse(TransientFailure::isTransient($auth));
    }

    public function testARequestExceptionWithoutAResponseFallsBackToTransport(): void
    {
        // No response means no status to judge on, and a request that never got
        // one is a transport failure.
        $this->assertTrue(TransientFailure::isTransient(
            new RequestException('no response', self::request()),
        ));
    }

    // -------------------------------------------------------------------------
    // The wrapped-exception chain. Three providers hide the informative
    // exception inside a bare one; without the walk, none of them classifies.
    // -------------------------------------------------------------------------

    public function testSglangStyleWrappingIsClassifiedThroughTheChain(): void
    {
        // SglangProvider::completeStream() throws exactly this shape:
        // new \RuntimeException('SGLANG request failed: ...', 0, $guzzleException)
        $wrapped = new \RuntimeException(
            'SGLANG request failed: 503 Service Unavailable',
            0,
            new ServerException('503', self::request(), new Response(503)),
        );

        $this->assertTrue(TransientFailure::isTransient($wrapped));
    }

    public function testSglangStyleWrappingOfAPermanentCauseStaysPermanent(): void
    {
        $wrapped = new \RuntimeException(
            'SGLANG request failed: 401 Unauthorized',
            0,
            new ClientException('401', self::request(), new Response(401)),
        );

        $this->assertFalse(TransientFailure::isTransient($wrapped));
    }

    /**
     * `statusCode()`'s `$status > 0` guard, which is what keeps a "never got a
     * response" answer from being read as a real HTTP code.
     *
     * A `getStatusCode()` of 0 must be reported as NO status, so the walk
     * continues to the inner exception. Without the guard, `statusIsTransient(0)`
     * is false and the walk returns immediately — the `AwsException` underneath,
     * which is the only link that knows this was a connection error, is never
     * consulted. Dropping `&& $status > 0` was green across 2863 tests.
     *
     * The outer link is deliberately not a `TransferException` and not itself an
     * `AwsException`: either would give a different clause the chance to answer
     * true and the guard would stop being what this test measures.
     */
    public function testAZeroStatusIsTreatedAsNoStatusSoTheWalkContinues(): void
    {
        $inner = new AwsException('cannot connect', new Command('Converse'), [
            'connection_error' => true,
        ]);
        $outer = new ZeroStatusFailure('no response was ever received', $inner);

        self::assertNotInstanceOf(TransferException::class, $outer);
        self::assertSame(0, $outer->getStatusCode(), 'the premise: a status-bearing link reporting 0');

        $this->assertTrue(
            TransientFailure::isTransient($outer),
            'a 0 means "no response", so the AwsException underneath is what must decide',
        );
    }

    /**
     * ...and the same guard must not turn a REAL status into "keep walking":
     * a 401 outside with a connection error inside is still permanent, because a
     * recognised status is definitive and stops the walk.
     */
    public function testARealStatusStillStopsTheWalkAtTheOuterLink(): void
    {
        $inner = new AwsException('cannot connect', new Command('Converse'), [
            'connection_error' => true,
        ]);

        $this->assertFalse(TransientFailure::isTransient(
            new ZeroStatusFailure('unauthorized', $inner, 401),
        ));
    }

    public function testBedrockStyleAwsConnectionErrorIsTransient(): void
    {
        $aws = new AwsException('cannot connect', new Command('Converse'), [
            'connection_error' => true,
        ]);

        $this->assertTrue(TransientFailure::isTransient(
            new \RuntimeException('bedrock completion failed', 0, $aws),
        ));
    }

    public function testBedrockStyleAwsThrottleStatusIsTransient(): void
    {
        $aws = new AwsException('throttled', new Command('Converse'), [
            'response' => new Response(429),
        ]);

        $this->assertTrue(TransientFailure::isTransient($aws));
    }

    public function testBedrockStyleAwsAuthStatusIsNotTransient(): void
    {
        $aws = new AwsException('bad signature', new Command('Converse'), [
            'response' => new Response(403),
        ]);

        $this->assertFalse(TransientFailure::isTransient($aws));
    }

    public function testOpenAiTransporterExceptionIsTransient(): void
    {
        // openai-php only ever constructs this around a PSR-18 client
        // exception, which is what makes the class itself sufficient evidence.
        $this->assertTrue(TransientFailure::isTransient(
            new TransporterException(new ConnectException('refused', self::request())),
        ));
    }

    public function testOpenAiErrorExceptionIsClassifiedOnItsStatus(): void
    {
        $overloaded = new ErrorException(
            ['message' => 'overloaded', 'type' => 'server_error', 'code' => null],
            503,
        );
        $badKey = new ErrorException(
            ['message' => 'invalid api key', 'type' => 'invalid_request_error', 'code' => null],
            401,
        );

        $this->assertTrue(TransientFailure::isTransient($overloaded));
        $this->assertFalse(TransientFailure::isTransient($badKey));
    }

    // -------------------------------------------------------------------------
    // Not-transient by default. The allow-list rule.
    // -------------------------------------------------------------------------

    public function testAnUnrecognisedExceptionIsNotTransient(): void
    {
        $this->assertFalse(TransientFailure::isTransient(new \RuntimeException('something')));
    }

    public function testLocalInputValidationIsNotTransient(): void
    {
        // VertexProvider's endpointFor()/anthropicBody() raise this for
        // locally-detectable bad input, and its catch is \Throwable, so this
        // exact shape reaches the classifier.
        $this->assertFalse(TransientFailure::isTransient(
            new \InvalidArgumentException('model id must not be a resource path'),
        ));
    }

    public function testAPermissionDenialRaisedInsideTheStreamLoopIsNotTransient(): void
    {
        // AgentManager::evaluateToolCalls() throws from inside the very foreach
        // the retry wraps. This is the case that makes the allow-list an
        // allow-list: a deny-list would have retried a denied tool call.
        $this->assertFalse(TransientFailure::isTransient(
            new \RuntimeException('SubAgent tool call denied by permission gate: Bash'),
        ));
    }

    public function testAClaudeCodeSubprocessFailureIsNotTransientAndThatIsAKnownGap(): void
    {
        // ClaudeCodeProvider throws bare \RuntimeExceptions carrying only prose
        // ("Claude Code exited with code 1: ..."), so there is nothing to
        // classify on and it is deliberately NOT retried rather than being
        // classified by pattern-matching its message. Pinned so the gap is a
        // recorded decision rather than an assumption.
        $this->assertFalse(TransientFailure::isTransient(
            new \RuntimeException('Claude Code exited with code 1: boom'),
        ));
        $this->assertFalse(TransientFailure::isTransient(
            new \RuntimeException('Failed to start Claude Code process'),
        ));
    }

    public function testACyclicCauseChainTerminates(): void
    {
        // Not producible by any provider here, but constructible, and an
        // unbounded walk over it would hang the turn rather than fail it.
        $inner = new \RuntimeException('inner');
        $outer = new \RuntimeException('outer', 0, $inner);
        (new \ReflectionProperty(\Exception::class, 'previous'))->setValue($inner, $outer);

        // Proven, not assumed: without this the walk would simply reach null and
        // the assertion below would pass whether or not the guard exists.
        $this->assertSame($outer, $inner->getPrevious(), 'the cycle under test must actually be a cycle');
        $this->assertSame($inner, $outer->getPrevious());

        $started = microtime(true);
        $this->assertFalse(TransientFailure::isTransient($outer));
        $this->assertLessThan(1.0, microtime(true) - $started, 'the walk must terminate, not spin');
    }

    // -------------------------------------------------------------------------
    // The error-RESPONSE channel (Vertex, Custom).
    // -------------------------------------------------------------------------

    public function testAnErrorResponseIsRetriedOnlyWhenExplicitlyClassifiedTransient(): void
    {
        $transient = new CompleteResponse(
            content: '',
            isError: true,
            errorMessage: '503',
            errorTransient: true,
        );
        $permanent = new CompleteResponse(
            content: '',
            isError: true,
            errorMessage: '401',
            errorTransient: false,
        );
        $unclassified = new CompleteResponse(content: '', isError: true, errorMessage: 'who knows');

        $this->assertTrue(TransientFailure::responseIsTransient($transient));
        $this->assertFalse(TransientFailure::responseIsTransient($permanent));
        $this->assertFalse(
            TransientFailure::responseIsTransient($unclassified),
            'null means unclassified, and unclassified is not retried',
        );
    }

    public function testASuccessfulResponseIsNeverTransientEvenIfFlagged(): void
    {
        // isError is the gate; a stray true on a successful response must not
        // cause a completed reply to be thrown away and re-requested.
        $ok = new CompleteResponse(content: 'hello', errorTransient: true);

        $this->assertFalse(TransientFailure::responseIsTransient($ok));
    }

    /**
     * DOMAIN: the constructor's DEFAULT, which is all one construction can pin.
     *
     * The old name said "every non-error response", which this cannot show —
     * `errorTransient` is a public promoted parameter, so any provider may pass
     * it on any response, and a census of every construction in `src/` would be
     * a different test that rots on the next one added. What actually protects a
     * successful reply is not the default but `responseIsTransient()`'s `isError`
     * gate, which {@see testASuccessfulResponseIsNeverTransientEvenIfFlagged()}
     * pins. This one pins the default, asserted off the parameter itself so it
     * cannot silently become `false` — a very different claim, since `false`
     * asserts "classified as permanent" where `null` says "nobody looked".
     */
    public function testTheFlagDefaultsToNullRatherThanFalse(): void
    {
        $this->assertNull((new CompleteResponse(content: 'hi'))->errorTransient);

        $parameter = null;
        foreach ((new \ReflectionClass(CompleteResponse::class))->getConstructor()?->getParameters() ?? [] as $candidate) {
            if ($candidate->getName() === 'errorTransient') {
                $parameter = $candidate;
            }
        }

        self::assertNotNull($parameter, 'the field this whole channel rides on must still exist');
        $this->assertTrue($parameter->isDefaultValueAvailable());
        $this->assertNull($parameter->getDefaultValue(), 'unclassified must be null, not a claim of permanence');
    }

    // -------------------------------------------------------------------------
    // The Anthropic error-object channel.
    // -------------------------------------------------------------------------

    public function testAnthropicOverloadedErrorIsTransient(): void
    {
        // THE case: an overloaded Anthropic-on-Vertex backend answers 200 with
        // this inside an SSE `error` event, so no status and no exception exist
        // to classify.
        $this->assertTrue(TransientFailure::anthropicErrorIsTransient([
            'type' => 'overloaded_error',
            'message' => 'Overloaded',
        ]));
    }

    public function testAnthropicRateLimitAndApiErrorsAreTransient(): void
    {
        $this->assertTrue(TransientFailure::anthropicErrorIsTransient(['type' => 'rate_limit_error']));
        $this->assertTrue(TransientFailure::anthropicErrorIsTransient(['type' => 'api_error']));
    }

    /**
     * @dataProvider permanentAnthropicErrorTypes
     */
    public function testThesePermanentAnthropicErrorTypesAreNotTransient(string $type): void
    {
        $this->assertFalse(
            TransientFailure::anthropicErrorIsTransient(['type' => $type]),
            $type . ' must NOT be retried',
        );
    }

    /** @return array<string, array{string}> */
    public static function permanentAnthropicErrorTypes(): array
    {
        return [
            'invalid_request_error' => ['invalid_request_error'],
            'authentication_error' => ['authentication_error'],
            'permission_error' => ['permission_error'],
            'not_found_error' => ['not_found_error'],
            'request_too_large' => ['request_too_large'],
        ];
    }

    public function testAMalformedAnthropicErrorObjectIsNotTransient(): void
    {
        $this->assertFalse(TransientFailure::anthropicErrorIsTransient(null));
        $this->assertFalse(TransientFailure::anthropicErrorIsTransient('overloaded_error'));
        $this->assertFalse(TransientFailure::anthropicErrorIsTransient([]));
        $this->assertFalse(TransientFailure::anthropicErrorIsTransient(['type' => ['overloaded_error']]));
    }

    // -------------------------------------------------------------------------
    // The backoff schedule, and the ceiling it has to fit under.
    // -------------------------------------------------------------------------

    public function testTheBackoffScheduleDoublesAndStopsWhenNoAttemptsRemain(): void
    {
        $base = TransientFailure::BASE_BACKOFF_MICROSECONDS;

        $this->assertSame($base, TransientFailure::backoffMicroseconds(1));
        $this->assertSame($base * 2, TransientFailure::backoffMicroseconds(2));
        $this->assertSame(
            0,
            TransientFailure::backoffMicroseconds(TransientFailure::MAX_ATTEMPTS),
            'the last attempt is not followed by a wait for a retry that will not happen',
        );
        $this->assertSame(0, TransientFailure::backoffMicroseconds(0));
        $this->assertSame(0, TransientFailure::backoffMicroseconds(-1));
    }

    public function testTheTotalBackoffIsTheSumOfEveryWaitItWouldPerform(): void
    {
        $expected = 0;
        for ($attempt = 1; $attempt < TransientFailure::MAX_ATTEMPTS; $attempt++) {
            $expected += TransientFailure::backoffMicroseconds($attempt);
        }

        $this->assertSame($expected, TransientFailure::totalBackoffMicroseconds());
        $this->assertGreaterThan(0, $expected, 'a retry policy that never waits is not a backoff');
    }

    /**
     * The relationship that makes the schedule safe, asserted against the
     * constant that enforces it rather than against a literal.
     *
     * DOMAIN: EngineBackend::COMPLETE_TIMEOUT_SECONDS is an IDLE ceiling, not a
     * total-turn one — it is re-armed on every frame the forked child streams.
     * No frame is emitted while TransientFailure is sleeping, so a whole
     * exhausted retry sequence is uninterrupted silence against that clock. If
     * the sleeps ever summed near it, a retry would get the entire turn
     * SIGKILLed from above: strictly worse than the one failure it was papering
     * over. An order of magnitude of headroom is the bar.
     */
    public function testTheWholeBackoffSequenceFitsFarInsideTheIdleTimeout(): void
    {
        $idleCeiling = new \ReflectionClassConstant(EngineBackend::class, 'COMPLETE_TIMEOUT_SECONDS');
        $idleSeconds = (int) $idleCeiling->getValue();

        $backoffSeconds = TransientFailure::totalBackoffMicroseconds() / 1_000_000;

        $this->assertGreaterThan(0, $idleSeconds);
        $this->assertLessThan(
            $idleSeconds / 10,
            $backoffSeconds,
            'the exhausted backoff must stay an order of magnitude under the idle ceiling it is silent against',
        );
    }

    public function testBackoffActuallySleepsForTheScheduledDuration(): void
    {
        // Pins that backoff() honours the schedule rather than merely computing
        // it — a no-op backoff would leave every other test here passing.
        $started = microtime(true);
        TransientFailure::backoff(1);
        $elapsed = (microtime(true) - $started) * 1_000_000;

        // The bound used to be 90% of the debt, "because usleep may return
        // marginally early on a loaded box". It cannot any more: backoff() now
        // re-sleeps against a `microtime(true)` deadline and only returns once
        // that deadline has PASSED, so the full debt is a property of the
        // implementation rather than a hope about the scheduler.
        $this->assertGreaterThanOrEqual(
            TransientFailure::backoffMicroseconds(1),
            $elapsed,
        );
    }

    /**
     * THE BACKOFF SURVIVES A SIGNAL, which is the whole reason it is a loop.
     *
     * MEASURED on this host, PHP 8.3.6: `usleep(1_000_000)` with a caught
     * signal delivered 200ms in returns after 0.2017s — PHP does not restart
     * the sleep across EINTR. Driven through this class before the fix, with
     * the empty SIGWINCH handler `SugarCraft\Core\Program` installs for the
     * whole TUI and a SIGWINCH raised 120ms into `backoff(1)`: 500000µs owed,
     * 119896µs slept. Three quarters of the wait gone.
     *
     * Those handlers are INHERITED ACROSS `EngineBackend::completeAsync()`'s
     * fork and nothing in the child resets them, so this is not a contrived
     * shape — a terminal resize during a 5xx retry storm is a user reaching for
     * their window, and it silently put the retry back on the failing upstream
     * with no wait at all.
     *
     * Run in a child process because the signal has to arrive while THIS call
     * is parked, and installing TUI signal handlers in the suite's own process
     * would leak into every test after it.
     */
    public function testBackoffSleepsItsWholeDebtEvenWhenASignalInterruptsTheSleep(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('pcntl_async_signals')) {
            $this->markTestSkipped('pcntl is required to deliver a signal mid-sleep');
        }

        $owed = TransientFailure::backoffMicroseconds(1);
        $slept = $this->backoffSleptWithSignalAt(120_000);

        $this->assertGreaterThanOrEqual(
            $owed,
            $slept,
            'a signal truncated the backoff, putting the retry straight back on the failing upstream',
        );
        // And it did not turn one interrupted sleep into many: the deadline is
        // re-read from the clock, so the handler's own cost comes OUT of the
        // backoff rather than being added to it.
        $this->assertLessThan($owed * 3, $slept, 'the re-sleep loop overshot its deadline');
    }

    /**
     * Run `TransientFailure::backoff(1)` in a child that has the TUI's SIGWINCH
     * handler installed and a grandchild raising it mid-sleep; report the
     * microseconds actually slept.
     */
    private function backoffSleptWithSignalAt(int $afterMicroseconds): int
    {
        $autoload = \dirname(__DIR__, 2) . '/vendor/autoload.php';

        $script = <<<PHP
            <?php
            declare(strict_types=1);
            require {$this->export($autoload)};

            pcntl_async_signals(true);
            pcntl_signal(SIGWINCH, static function (): void {});

            \$parent = getmypid();
            \$pid = pcntl_fork();
            if (\$pid === 0) {
                usleep({$afterMicroseconds});
                posix_kill(\$parent, SIGWINCH);
                exit(0);
            }

            \$started = microtime(true);
            SugarCraft\Crush\Providers\TransientFailure::backoff(1);
            \$slept = (int) round((microtime(true) - \$started) * 1_000_000);

            \$status = 0;
            pcntl_waitpid(\$pid, \$status);
            fwrite(STDOUT, (string) \$slept);
            PHP;

        $file = tempnam(sys_get_temp_dir(), 'backoff_signal_');
        self::assertIsString($file);
        file_put_contents($file, $script);

        try {
            $process = proc_open([PHP_BINARY, $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $out = (string) stream_get_contents($pipes[1]);
            $err = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        } finally {
            @unlink($file);
        }

        self::assertSame('', trim($err), 'the backoff child wrote to stderr');
        self::assertMatchesRegularExpression('/^\d+$/', trim($out), 'the child reported no sleep figure');

        return (int) trim($out);
    }

    private function export(string $value): string
    {
        return var_export($value, true);
    }

    public function testTheLastAttemptCostsNoWait(): void
    {
        $started = microtime(true);
        TransientFailure::backoff(TransientFailure::MAX_ATTEMPTS);

        $this->assertLessThan(0.05, microtime(true) - $started);
    }

    public function testTheAttemptCeilingLeavesRoomForAtLeastOneRetry(): void
    {
        $this->assertGreaterThanOrEqual(
            2,
            TransientFailure::MAX_ATTEMPTS,
            'MAX_ATTEMPTS of 1 would make every retry loop in this library a no-op that still looks wired',
        );
        $this->assertLessThanOrEqual(3, TransientFailure::MAX_ATTEMPTS, 'the plan asks for 2-3');
    }
}

/**
 * A PSR-18 network exception from a client that is not Guzzle.
 *
 * Deliberately minimal: it implements `NetworkExceptionInterface` and nothing
 * else, so it can only be classified by the clause that keys on that interface.
 * `GuzzleHttp\Exception\ConnectException` also implements it but extends
 * `TransferException` as well, which is why it cannot stand in for this — see
 * {@see TransientFailureTest::testANonGuzzlePsr18NetworkExceptionIsTransient()}.
 */
final class PsrNetworkFailure extends \RuntimeException implements NetworkExceptionInterface
{
    public function __construct(private readonly RequestInterface $psrRequest)
    {
        parent::__construct('name resolution failed');
    }

    public function getRequest(): RequestInterface
    {
        return $this->psrRequest;
    }
}

/**
 * A status-bearing failure whose status can be 0 — the shape `AwsException` and
 * openai-php's `ErrorException` both present, and the reason
 * {@see \SugarCraft\Crush\Providers\TransientFailure} reaches them with
 * `method_exists()` rather than a class check.
 */
final class ZeroStatusFailure extends \RuntimeException
{
    public function __construct(string $message, ?\Throwable $previous = null, private readonly int $status = 0)
    {
        parent::__construct($message, 0, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }
}

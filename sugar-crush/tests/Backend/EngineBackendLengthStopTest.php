<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\EmbeddingsRequest;
use SugarCraft\Crush\Providers\EmbeddingsResponse;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * E707 (round 81), the control half: the operator's `maxOutputTokens` key and
 * the ceiling verdict's last mile.
 *
 * Three claims, paired the way the dispatch settings next door (see
 * {@see EngineBackendParallelConfigTest}) pair theirs. First the resolver:
 * unset or nonsense answers NULL, and NULL is the whole point — it is what
 * keeps a turn byte-identical to the pre-E707 request, provider defaults and
 * all (E646's opt-in discipline: this key is a request parameter an operator
 * raises, never a behavior the code changes under them). Second, the decision
 * REACHES the provider: a real turn's CompleteRequest carries the configured
 * ceiling, witnessed at the wire-facing seam itself. Third the verdict rides
 * home: EngineBackend ORs length stops across the agentic steps, and the
 * forked completeAsync() child's result frame carries the flag across the
 * serialize boundary so the async half of the app tells the same truth as the
 * sync half.
 *
 * HOME is redirected to a sandbox for the whole class on BOTH spellings —
 * the process environment and the superglobal (same convention as
 * EngineBackendParallelConfigTest), so nothing here can read or write the
 * real ~/.sugar-crush/config.json.
 */
final class EngineBackendLengthStopTest extends TestCase
{
    private string $sandboxDir;

    private string|false $originalHome;

    private mixed $originalServerHome = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sandboxDir = sys_get_temp_dir() . '/sc_e707_length_stop_' . bin2hex(random_bytes(6));
        mkdir($this->sandboxDir . '/home', 0o700, true);

        $this->originalHome = getenv('HOME');
        $this->originalServerHome = $_SERVER['HOME'] ?? null;
        putenv('HOME=' . $this->sandboxDir . '/home');
        $_SERVER['HOME'] = $this->sandboxDir . '/home';
    }

    protected function tearDown(): void
    {
        if ($this->originalHome === false) {
            putenv('HOME');
        } else {
            putenv('HOME=' . $this->originalHome);
        }

        if (null === $this->originalServerHome) {
            unset($_SERVER['HOME']);
        } else {
            $_SERVER['HOME'] = $this->originalServerHome;
        }

        $this->removeSandboxTree();

        parent::tearDown();
    }

    // =========================================================================
    // The resolver: NULL is a real answer
    // =========================================================================

    public function testTheResolverAnswersNullWhenNothingIsConfigured(): void
    {
        $this->assertNull($this->resolveFrom([]));
        $this->assertNull($this->ambientCeiling(), 'a fresh sandbox HOME has no key — the byte-identity baseline');
    }

    /**
     * Every nonsense shape falls back to NULL — the provider default — rather
     * than to a grown-up number: unlike the parallel deadline, this resolver
     * HAS no default of its own to fall back TO, and inventing one would
     * silently raise (or lower) every operator's paid ceiling.
     *
     * @dataProvider unusableCeilings
     */
    public function testNonsenseCeilingsResolveToNull(mixed $value): void
    {
        $this->assertNull($this->resolveFrom(['maxOutputTokens' => $value]), var_export($value, true) . ' must answer "operator said nothing"');
    }

    /** @return iterable<string, array{mixed}> */
    public static function unusableCeilings(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-5];
        yield 'the empty string' => [''];
        yield 'a word' => ['many'];
        yield 'a bool' => [true];
        yield 'an array' => [[1024]];
        yield 'a fraction under one' => [0.5];
        yield 'infinite' => [INF];
        yield 'not-a-number' => [NAN];
    }

    public function testUsableCeilingsResolveToIntegers(): void
    {
        $this->assertSame(8192, $this->resolveFrom(['maxOutputTokens' => 8192]));
        $this->assertSame(4096, $this->resolveFrom(['maxOutputTokens' => '4096']), 'a numeric string from an env-shaped source still counts');
        $this->assertSame(2047, $this->resolveFrom(['maxOutputTokens' => 2047.9]), 'truncation toward zero, same rule as the deadline resolver');
        $this->assertSame(1, $this->resolveFrom(['maxOutputTokens' => 1]), 'the smallest honoured ceiling is one token');
    }

    /**
     * There is deliberately NO upper bound: what counts as this model's output
     * cap is a fact about the endpoint, not about this code, and clamping here
     * would bake one provider's assumption into every operator's request.
     */
    public function testTheResolverDoesNotInventAnUpperBound(): void
    {
        $this->assertSame(10_000_000, $this->resolveFrom(['maxOutputTokens' => 10_000_000]));
    }

    // =========================================================================
    // …and the decision reaches the provider's request
    // =========================================================================

    public function testAnUnsetKeySendsNoOverrideOnTheWire(): void
    {
        $witness = new \ArrayObject();
        EngineBackend::new($this->recordingProvider($witness), 'e707')->complete([Message::user('go')]);

        /** @var CompleteRequest $sent */
        $sent = $witness['request'];
        $this->assertNull($sent->maxTokens, 'with nothing configured the request must carry exactly what it carried before E707: no override at all');
    }

    public function testThePersistedKeyReachesTheProviderRequest(): void
    {
        Bootstrap::writeUserConfig(['maxOutputTokens' => 8192]);

        $witness = new \ArrayObject();
        EngineBackend::new($this->recordingProvider($witness), 'e707')->complete([Message::user('go')]);

        /** @var CompleteRequest $sent */
        $sent = $witness['request'];
        $this->assertSame(8192, $sent->maxTokens, 'the resolver in isolation is only half the claim — the per-turn read must land on the wire');
    }

    // =========================================================================
    // The verdict's last mile: OR across steps, sync and forked
    // =========================================================================

    public function testACleanSingleStepTurnLandsUnstained(): void
    {
        $reply = EngineBackend::new($this->answeringDouble(truncated: false), 'e707')
            ->complete([Message::user('go')]);

        $this->assertFalse($reply->lengthStopped);
    }

    public function testALengthStoppedAnswerMarksTheRootMessage(): void
    {
        $reply = EngineBackend::new($this->answeringDouble(truncated: true), 'e707')
            ->complete([Message::user('go')]);

        $this->assertTrue($reply->lengthStopped, 'the root Message is what Chat settles into history — if the flag died at this conversion seam the notice could never fire');
    }

    public function testTheVerdictOrsAcrossToolLoopSteps(): void
    {
        // Step one gets cut off mid-tool-call-turn, step two ends cleanly:
        // the turn as a WHOLE still stopped at the ceiling, and the notice
        // owes the user that truth even though the final text looks complete.
        $reply = EngineBackend::new($this->toolThenAnswer(truncateStep: 1), 'e707')
            ->withTools([$this->noopTool()])
            ->complete([Message::user('go')]);

        $this->assertSame('after the tool', $reply->content);
        $this->assertTrue($reply->lengthStopped, 'a stop on ANY step of the agentic loop marks the turn');

        $cleanTurn = EngineBackend::new($this->toolThenAnswer(truncateStep: 0), 'e707')
            ->withTools([$this->noopTool()])
            ->complete([Message::user('go')]);

        $this->assertFalse($cleanTurn->lengthStopped, 'and two clean steps stay clean — the OR never conjures a stop');
    }

    public function testTheForkedChildCarriesTheVerdictAcrossTheResultFrame(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            // Same host truth as the neighbours: pcntl exists here, so this
            // gate does not fire and the round-trip below really runs.
            self::markTestSkipped('completeAsync() takes the blocking fallback without pcntl and the frame never crosses a serialize boundary');
        }

        $resolved = $this->drainUntilSettled(
            EngineBackend::new($this->answeringDouble(truncated: true), 'e707')
                ->completeAsync([Message::user('go')]),
        );

        $this->assertInstanceOf(Message::class, $resolved);
        $this->assertSame('the stop says cut', $resolved->content);
        $this->assertTrue($resolved->lengthStopped, 'the async half of the app must tell the same truth as the sync half');
    }

    public function testAFrameWithoutTheKeySettlesClean(): void
    {
        // The strict `=== true` law on the read side: a frame written by a
        // pre-E707 child (key absent) — or one carrying garbage there —
        // settles as "the child did not say the ceiling bit".
        $stopped = $this->settleFrame(['ok' => true, 'content' => 'x', 'lengthStopped' => true]);
        $this->assertTrue($stopped->lengthStopped);

        $legacy = $this->settleFrame(['ok' => true, 'content' => 'x']);
        $this->assertFalse($legacy->lengthStopped);

        $garbage = $this->settleFrame(['ok' => true, 'content' => 'x', 'lengthStopped' => 'yes']);
        $this->assertFalse($garbage->lengthStopped, 'a truthy string is not the child having SAID it');
    }

    // =========================================================================
    // harness
    // =========================================================================

    /** @param array<string, mixed> $config */
    private function resolveFrom(array $config): ?int
    {
        return (new \ReflectionMethod(EngineBackend::class, 'maxOutputTokens'))->invoke(null, $config);
    }

    /** The same resolver on its no-argument path — the ambient read the turn itself uses. */
    private function ambientCeiling(): ?int
    {
        return (new \ReflectionMethod(EngineBackend::class, 'maxOutputTokens'))->invoke(null);
    }

    /** @param array<string, mixed> $frame */
    private function settleFrame(array $frame): Message
    {
        $backend = EngineBackend::new($this->answeringDouble(truncated: false), 'e707');
        $deferred = new \React\Promise\Deferred();

        (new \ReflectionMethod($backend, 'settleFromResultFrame'))
            ->invoke($backend, $frame, $deferred, null);

        $resolved = null;
        $deferred->promise()->then(static function (Message $m) use (&$resolved): void {
            $resolved = $m;
        });

        $this->assertInstanceOf(Message::class, $resolved, 'the frame must settle, not reject');

        return $resolved;
    }

    /**
     * Pump the loop until the promise settles, with one safety timer so a
     * wedged child reds the test instead of the CI job. (The blocking-fallback
     * hosts never reach here; on them the promise resolves synchronously and
     * the loop exits without the timer firing.)
     */
    private function drainUntilSettled(\React\Promise\PromiseInterface $promise): mixed
    {
        $loop = Loop::get();
        $settled = false;
        $value = null;
        $failure = null;

        $promise->then(
            static function ($v) use (&$settled, &$value, $loop): void {
                $settled = true;
                $value = $v;
                $loop->stop();
            },
            static function (\Throwable $e) use (&$settled, &$failure, $loop): void {
                $settled = true;
                $failure = $e;
                $loop->stop();
            },
        );

        if ($settled) {
            return $value;
        }

        $watchdog = $loop->addTimer(30.0, static function () use ($loop, &$failure): void {
            $failure = new \RuntimeException('the forked completion never settled within the safety window');
            $loop->stop();
        });

        $loop->run();
        $loop->cancelTimer($watchdog);

        if ($failure !== null) {
            $this->fail('forked turn failed: ' . $failure->getMessage());
        }

        return $value;
    }

    /**
     * A batch provider whose one answer optionally reports the ceiling, and
     * whose request arrives whole in `$witness` — the wire-facing half of the
     * threading claim.
     */
    private function recordingProvider(\ArrayObject $witness): ProviderInterface
    {
        return new class ($witness) implements ProviderInterface {
            public function __construct(private readonly \ArrayObject $seen) {}

            public function name(): string
            {
                return 'e707-recording';
            }

            public function supportsStreaming(): bool
            {
                return false;
            }

            public function supportsFunctionCalling(): bool
            {
                return false;
            }

            public function supportsVision(): bool
            {
                return false;
            }

            public function supportsJsonSchema(): bool
            {
                return false;
            }

            public function contextWindow(): int
            {
                return 1000;
            }

            public function costPer1kTokens(string $model, string $direction): float
            {
                return 0.0;
            }

            public function complete(CompleteRequest $request): CompleteResponse
            {
                $this->seen['request'] = $request;

                return new CompleteResponse(content: 'not reached — the batch answer short-circuits first');
            }

            public function completeStream(CompleteRequest $request): \Generator
            {
                yield new CompleteResponse(content: '');
            }

            public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
            {
                return new EmbeddingsResponse(embeddings: []);
            }
        };
    }

    private function answeringDouble(bool $truncated): ProviderInterface
    {
        return new class ($truncated) implements ProviderInterface {
            public function __construct(private readonly bool $stopped) {}

            public function name(): string
            {
                return 'e707-answering';
            }

            public function supportsStreaming(): bool
            {
                return false;
            }

            public function supportsFunctionCalling(): bool
            {
                return false;
            }

            public function supportsVision(): bool
            {
                return false;
            }

            public function supportsJsonSchema(): bool
            {
                return false;
            }

            public function contextWindow(): int
            {
                return 1000;
            }

            public function costPer1kTokens(string $model, string $direction): float
            {
                return 0.0;
            }

            public function complete(CompleteRequest $request): CompleteResponse
            {
                return new CompleteResponse(content: 'the stop says cut', truncated: $this->stopped);
            }

            public function completeStream(CompleteRequest $request): \Generator
            {
                yield new CompleteResponse(content: '');
            }

            public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
            {
                return new EmbeddingsResponse(embeddings: []);
            }
        };
    }

    /**
     * Two agentic steps — a tool call, then the final answer — with the
     * ceiling stop attached to whichever step the test names (1 = the
     * tool-calling turn, 2 = the answer, 0 = neither).
     */
    private function toolThenAnswer(int $truncateStep): ProviderInterface
    {
        return new class ($truncateStep) implements ProviderInterface {
            private int $visits = 0;

            public function __construct(private readonly int $stoppedOn) {}

            public function name(): string
            {
                return 'e707-two-step';
            }

            public function supportsStreaming(): bool
            {
                return false;
            }

            public function supportsFunctionCalling(): bool
            {
                return true;
            }

            public function supportsVision(): bool
            {
                return false;
            }

            public function supportsJsonSchema(): bool
            {
                return false;
            }

            public function contextWindow(): int
            {
                return 1000;
            }

            public function costPer1kTokens(string $model, string $direction): float
            {
                return 0.0;
            }

            public function complete(CompleteRequest $request): CompleteResponse
            {
                $this->visits++;

                if ($this->visits === 1) {
                    return new CompleteResponse(
                        content: 'reaching for the tool',
                        toolCalls: [new ToolCall('e707_call_1', 'noop', [])],
                        truncated: $this->stoppedOn === 1,
                    );
                }

                return new CompleteResponse(
                    content: 'after the tool',
                    truncated: $this->stoppedOn === 2,
                );
            }

            public function completeStream(CompleteRequest $request): \Generator
            {
                yield new CompleteResponse(content: '');
            }

            public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
            {
                return new EmbeddingsResponse(embeddings: []);
            }
        };
    }

    private function noopTool(): Tool
    {
        return new class implements Tool {
            public function name(): string
            {
                return 'noop';
            }

            public function description(): string
            {
                return 'E707 two-step fixture tool';
            }

            public function inputSchema(): array
            {
                return [];
            }

            public function execute(array $args): ToolResult
            {
                return new ToolResult(toolCallId: $args['id'] ?? '', content: 'fine');
            }
        };
    }

    private function removeSandboxTree(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->sandboxDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($this->sandboxDir);
    }
}

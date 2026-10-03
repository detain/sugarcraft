<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Crush\Backend\CacheHealthWatch;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\EmbeddingsRequest;
use SugarCraft\Crush\Providers\EmbeddingsResponse;
use SugarCraft\Crush\Providers\MarksPromptCache;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Tests\Support\DiscardsErrorLogTrait;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Usage;

/**
 * P10.S3: {@see \SugarCraft\Crush\Providers\CacheBreakpoints::observeCacheHealth()}
 * had no caller, so a session whose prompt cache never hit — a prefix under the
 * model's minimum, marks that never reached the wire — paid the full input
 * rate on every request and nothing ever said so. The turn loop now feeds it
 * each step's usage, for providers that mark the request, and says it once.
 */
final class EngineBackendCacheHealthTest extends TestCase
{
    use DiscardsErrorLogTrait;
    use HomeSandboxTrait;

    private string $home;

    protected function setUp(): void
    {
        parent::setUp();
        // complete() reads the user config, so the turn must not see the
        // developer's ~/.sugar-crush.
        $this->home = sys_get_temp_dir() . '/sc_eb_cache_health_' . bin2hex(random_bytes(4));
        $this->useHomeSandbox($this->home);

        RuntimeNoticeSink::reset();
        RuntimeNoticeSink::arm(crossFork: false);
        self::assertTrue(RuntimeNoticeSink::isArmed());
    }

    protected function tearDown(): void
    {
        RuntimeNoticeSink::reset();
        $this->restoreHomeSandbox();
        @rmdir($this->home);
        parent::tearDown();
    }

    /**
     * PER STEP, not per turn: three provider calls inside ONE turn (two that
     * call a tool, one that answers) are three reports.
     */
    public function testThreeZeroCacheStepsInOneTurnRaiseTheNoticeOnce(): void
    {
        $provider = self::provider(marks: true, toolCallingSteps: 2);
        $backend = EngineBackend::new($provider, 'claude-sonnet-4-6');

        self::withErrorLogDiscarded(static function () use ($backend): void {
            $backend->complete([Message::user('go')]);
        });

        $this->assertSame(3, $provider->calls, 'the turn made three provider calls');
        $notices = RuntimeNoticeSink::drain();
        $this->assertCount(1, $notices);
        $this->assertStringContainsString('3 consecutive responses', $notices[0]);
    }

    /**
     * The streak spans turns and the notice spans the session: a fourth and
     * fifth zero turn say nothing more, and a clone of the backend — what
     * every wither returns — shares the same watch.
     */
    public function testTheStreakSpansTurnsAndTheNoticeIsOncePerSession(): void
    {
        $provider = self::provider(marks: true);
        $backend = EngineBackend::new($provider, 'claude-sonnet-4-6');

        $drained = [];
        self::withErrorLogDiscarded(static function () use ($backend, &$drained): void {
            for ($turn = 0; $turn < 2; $turn++) {
                $backend->complete([Message::user('go')]);
            }
            $drained[] = RuntimeNoticeSink::drain();

            $backend->withMaxSteps(10)->complete([Message::user('go')]);
            $drained[] = RuntimeNoticeSink::drain();

            $backend->complete([Message::user('go')]);
            $backend->withMaxSteps(5)->complete([Message::user('go')]);
            $drained[] = RuntimeNoticeSink::drain();
        });

        $this->assertSame([], $drained[0], 'two zero turns are not a pattern yet');
        $this->assertCount(1, $drained[1], 'the third, on a clone, completes the streak');
        $this->assertSame([], $drained[2], 'and is not repeated by later turns or clones');
    }

    /**
     * Never warn for a provider that does not mark: `openai` and `sglang`
     * cache without breakpoints, and a provider that reports zeros there has
     * done nothing a user could fix.
     */
    public function testAProviderThatDoesNotMarkIsNeverWarned(): void
    {
        $backend = EngineBackend::new(self::provider(marks: null, toolCallingSteps: 4), 'm');

        self::withErrorLogDiscarded(static function () use ($backend): void {
            $backend->complete([Message::user('go')]);
        });

        $this->assertSame([], RuntimeNoticeSink::drain());
        $this->assertSame(['zeroReports' => 0, 'noticed' => false], self::watch($backend)->state());
    }

    /**
     * Nor for a marking provider whose marks are off for this model — the
     * `promptCache` setting, the kill switch, or a family that never cached.
     */
    public function testAMarkingProviderWithTheMarksOffIsNeverWarned(): void
    {
        $backend = EngineBackend::new(self::provider(marks: false, toolCallingSteps: 4), 'claude-3-sonnet');

        self::withErrorLogDiscarded(static function () use ($backend): void {
            $backend->complete([Message::user('go')]);
        });

        $this->assertSame([], RuntimeNoticeSink::drain());
    }

    /**
     * The no-tools summary request a stopped turn makes is a provider call
     * like any other step and is observed too: two steps exhaust a
     * two-step budget, and the summary is the third zero.
     */
    public function testTheStoppedTurnsSummaryRequestIsObserved(): void
    {
        $provider = self::provider(marks: true, toolCallingSteps: 99);
        $backend = EngineBackend::new($provider, 'claude-sonnet-4-6')->withMaxSteps(2);

        self::withErrorLogDiscarded(static function () use ($backend): void {
            $backend->complete([Message::user('go')]);
        });

        $this->assertSame(3, $provider->calls, 'two budgeted steps and one summary');
        $this->assertCount(1, RuntimeNoticeSink::drain());
    }

    /**
     * ACROSS THE FORK. On the TUI path every turn runs in a forked child whose
     * copy of the watch dies with it: without the carry the parent's streak
     * never advances, three one-step turns say nothing, and a session whose
     * turns are each three steps long hears the notice on every turn.
     */
    public function testForkedTurnsCarryTheStreakHome(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            self::markTestSkipped('completeAsync() takes the blocking fallback without pcntl; there is no child to carry the streak home');
        }

        $backend = EngineBackend::new(self::provider(marks: true), 'claude-sonnet-4-6');

        $states = [];
        // The child's warn() writes its forensic copy through the inherited
        // ini, so the diversion covers the children too.
        self::withErrorLogDiscarded(function () use ($backend, &$states): void {
            $this->awaitForkedTurn($backend->completeAsync([Message::user('go')]));
            $this->awaitForkedTurn($backend->completeAsync([Message::user('go')]));
            $states[] = self::watch($backend)->state();

            // The child raises the notice itself; with the in-process sink its
            // row stays in the child, so the carried bit is what is checked.
            $this->awaitForkedTurn($backend->completeAsync([Message::user('go')]));
            $states[] = self::watch($backend)->state();
        });

        $this->assertSame(['zeroReports' => 2, 'noticed' => false], $states[0]);
        $this->assertSame(['zeroReports' => 3, 'noticed' => true], $states[1]);
    }

    public function testAResultFrameHandsItsStreakToTheParentsWatch(): void
    {
        $backend = EngineBackend::new(self::provider(marks: true), 'm');

        $this->settle($backend, ['kind' => 'result', 'ok' => true, 'content' => 'x', 'cacheHealth' => ['zeroReports' => 2, 'noticed' => false]]);
        $this->assertSame(['zeroReports' => 2, 'noticed' => false], self::watch($backend)->state());

        // A failed turn's steps still counted.
        $this->settle($backend, ['kind' => 'result', 'ok' => false, 'error' => 'boom', 'cacheHealth' => ['zeroReports' => 3, 'noticed' => true]]);
        $this->assertSame(['zeroReports' => 3, 'noticed' => true], self::watch($backend)->state());

        // A frame from a child that predates the key changes nothing.
        $this->settle($backend, ['kind' => 'result', 'ok' => true, 'content' => 'x']);
        $this->assertSame(['zeroReports' => 3, 'noticed' => true], self::watch($backend)->state());
    }

    private static function watch(EngineBackend $backend): CacheHealthWatch
    {
        $watch = (new \ReflectionProperty(EngineBackend::class, 'cacheHealth'))->getValue($backend);
        self::assertInstanceOf(CacheHealthWatch::class, $watch);

        return $watch;
    }

    /**
     * @param array<string, mixed> $frame
     */
    private function settle(EngineBackend $backend, array $frame): void
    {
        $deferred = new Deferred();
        (new \ReflectionMethod($backend, 'settleFromResultFrame'))->invoke($backend, $frame, $deferred, null);
        // A rejection is an expected outcome for the failed frame; swallow it
        // so it is not reported as unhandled.
        $deferred->promise()->then(null, static fn(\Throwable $e): null => null);
    }

    /**
     * Pump the shared loop until the forked turn settles, bounded by one
     * safety timer so a wedged child reds the test instead of hanging it.
     */
    private function awaitForkedTurn(PromiseInterface $promise): Message
    {
        $loop = Loop::get();
        $outcome = null;
        $settle = static function (mixed $v) use (&$outcome, $loop): void {
            $outcome = $v;
            $loop->stop();
        };
        $promise->then($settle, $settle);

        if ($outcome === null) {
            $guard = $loop->addTimer(30.0, static function () use (&$outcome, $loop): void {
                $outcome = new \RuntimeException('the forked turn never settled');
                $loop->stop();
            });
            $loop->run();
            $loop->cancelTimer($guard);
        }

        if ($outcome instanceof \Throwable) {
            $this->fail('forked turn failed: ' . $outcome->getMessage());
        }
        $this->assertInstanceOf(Message::class, $outcome);

        return $outcome;
    }

    /**
     * A provider whose every response reports BOTH cache buckets measured at
     * zero. `$marks` null builds one that does not implement
     * {@see MarksPromptCache} at all; true/false is its answer. The first
     * `$toolCallingSteps` calls ask for a tool nobody registered — Runtime
     * settles that as an error result and the loop takes another step — and
     * every call after answers.
     */
    private static function provider(?bool $marks, int $toolCallingSteps = 0): ProviderInterface
    {
        $responder = static function (int $call) use ($toolCallingSteps): CompleteResponse {
            return new CompleteResponse(
                content: $call <= $toolCallingSteps ? 'checking' : 'done',
                toolCalls: $call <= $toolCallingSteps ? [new ToolCall('call_' . $call, 'no_such_tool', [])] : null,
                usage: Usage::new(100, 0.0, 80, 20, 0, 0),
            );
        };

        if ($marks === null) {
            return new class ($responder) implements ProviderInterface {
                public int $calls = 0;

                public function __construct(private readonly \Closure $responder) {}
                public function name(): string { return 'zero-cache-unmarked'; }
                public function supportsStreaming(): bool { return false; }
                public function supportsFunctionCalling(): bool { return true; }
                public function supportsVision(): bool { return false; }
                public function supportsJsonSchema(): bool { return false; }
                public function contextWindow(): int { return 100_000; }
                public function costPer1kTokens(string $model, string $direction): float { return 0.0; }

                public function complete(CompleteRequest $request): CompleteResponse
                {
                    return ($this->responder)(++$this->calls);
                }

                public function completeStream(CompleteRequest $request): \Generator
                {
                    yield $this->complete($request);
                }

                public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
                {
                    return new EmbeddingsResponse([]);
                }
            };
        }

        return new class ($responder, $marks) implements ProviderInterface, MarksPromptCache {
            public int $calls = 0;

            public function __construct(private readonly \Closure $responder, private readonly bool $marks) {}
            public function marksPromptCache(string $model): bool { return $this->marks; }
            public function name(): string { return 'zero-cache-marked'; }
            public function supportsStreaming(): bool { return false; }
            public function supportsFunctionCalling(): bool { return true; }
            public function supportsVision(): bool { return false; }
            public function supportsJsonSchema(): bool { return false; }
            public function contextWindow(): int { return 100_000; }
            public function costPer1kTokens(string $model, string $direction): float { return 0.0; }

            public function complete(CompleteRequest $request): CompleteResponse
            {
                return ($this->responder)(++$this->calls);
            }

            public function completeStream(CompleteRequest $request): \Generator
            {
                yield $this->complete($request);
            }

            public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
            {
                return new EmbeddingsResponse([]);
            }
        };
    }
}

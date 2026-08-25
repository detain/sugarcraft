<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\Timer\Timer;
use React\EventLoop\TimerInterface;
use React\Promise\PromiseInterface;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\EmbeddingsRequest;
use SugarCraft\Crush\Providers\EmbeddingsResponse;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Tools\ToolCall;

/**
 * **E456 — a long think must not be killed as a hung provider.**
 *
 * A user lost a turn to `Provider request timed out after 120s without
 * progress` while the model was mid-think.
 * {@see \SugarCraft\Crush\Runtime::runStreaming()} gated its live observer on
 * `$response->content !== ''`, and that observer was the ONLY thing writing a
 * frame across {@see EngineBackend::completeAsync()}'s fork, while the parent
 * resets its idle deadline only when a frame arrives. A reasoning-only chunk
 * carries `content: ''` — so a model that thought for two minutes wrote no
 * frames, reset no deadline, and was SIGKILLed while it was working.
 *
 * ## Why the fix is a channel and not a bigger number
 *
 * The timer was never the defect; its DEFINITION OF PROGRESS was. Raising
 * {@see EngineBackend}'s ceiling relocates the bug, removing it resurrects the
 * hang the ceiling exists to bound, and a blanket total-request timeout on an
 * LLM call is wrong outright — a completion can legitimately run tens of
 * minutes. So `$onProgress`/`$onReasoning` carries every chunk that did NOT
 * reach `$onToken`, on a frame kind of its own.
 *
 * ## Why a distinct frame kind and not a `token` frame
 *
 * `$onToken`'s bytes accumulate into the `$buffer` that becomes the
 * {@see AssistantMessage} fed back to the model on the next agentic step and
 * checkpointed into the transcript. Routing thinking through that channel
 * would corrupt the CONVERSATION, not merely the display —
 * {@see testAThoughtNeverEntersTheAssistantsOwnWords()} is what pins it.
 *
 * ## The defect is a FAMILY, and its members are killed by different mutations
 *
 * Three real chunk shapes carry `content === ''`:
 *
 *   - **reasoning-only** — the reported case;
 *   - **tool-call-only** — a chunk carrying nothing but the structure of a
 *     call;
 *   - **usage-only** — `VertexProvider`'s `message_start` reports input tokens
 *     with no content at all.
 *
 * MEASURED, and worth stating because the obvious mutation is the misleading
 * one: re-gating the reasoning announcement on `content !== ''` — the mutation
 * the round brief prescribed — does NOT redden the reasoning-only case, because
 * the `elseif` beside it still announces any chunk that carries reasoning text.
 * It reddens the members that have NOTHING to show, which is why
 * {@see testATurnWhoseChunksCarryOnlyUsageSurvivesTheIdleCeiling()} exists and
 * is not a decorative sibling of the reasoning test. See the mutation table in
 * the round report.
 *
 * ## How a 120-second ceiling is crossed in under a second
 *
 * {@see ScaledClockLoop} is a real event loop — real `stream_select()` on the
 * real socket pair, real frames off a real forked child — whose TIMERS run on a
 * clock scaled so one real second is
 * {@see ScaledClockLoop::VIRTUAL_SECONDS_PER_REAL_SECOND} virtual ones. The
 * code under test calls `addTimer(120)` unchanged and the loop genuinely
 * carries its clock past 120; the assertions check the high-water mark rather
 * than trusting the arithmetic. The scale is chosen so that the property the
 * fix delivers and the property it does not are both far from the boundary:
 *
 *   - a `usleep()` between chunks is a GUARANTEED LOWER BOUND on real elapsed
 *     time, so "the clock passed the ceiling" cannot flake — a killed turn is
 *     always a real verdict;
 *   - the per-chunk gap is ~10 virtual seconds against a 120 ceiling, so a
 *     20 ms sleep would have to take twelve times as long as asked before a
 *     surviving turn could be reported as killed.
 *
 * PHP 8.3.6 on this host.
 */
final class ReasoningProgressTest extends TestCase
{
    /**
     * Chunks the streaming doubles emit before they answer, and the real pause
     * between them. 40 x 20 ms is at least 0.8 s of real time, which
     * {@see ScaledClockLoop} reports as at least 400 virtual seconds — more
     * than three times the ceiling — while each individual gap is about 10.
     */
    private const THINK_CHUNKS = 40;

    private const CHUNK_PAUSE_MICROS = 20_000;

    private ?LoopInterface $previousLoop = null;

    protected function tearDown(): void
    {
        // Restore the SUITE's loop object, not a fresh StreamSelectLoop:
        // tests/bootstrap.php pins one instance for the whole run and other
        // files have registered on it.
        if ($this->previousLoop !== null) {
            Loop::set($this->previousLoop);
            $this->previousLoop = null;
        }

        parent::tearDown();
    }

    // =====================================================================
    // the clock tests - real fork, real socket, scaled timer clock
    // =====================================================================

    /**
     * The reported bug. A model that only thinks, for longer than the idle
     * ceiling, must still get to answer.
     */
    public function testATurnThatOnlyThinksSurvivesTheIdleCeiling(): void
    {
        $this->requireFork();

        $thoughts = [];
        $tokens = [];
        $run = $this->runOnScaledClock(
            new StreamingDouble(self::THINK_CHUNKS, self::CHUNK_PAUSE_MICROS, 'reasoning', 'the answer'),
            $tokens,
            $thoughts,
        );

        $this->assertTurnSurvivedPastTheCeiling($run);
        $this->assertSame('the answer', $run['message']->content);
        $this->assertSame(['the answer'], $tokens, 'the assistant text channel must carry only the answer');
        $this->assertCount(
            self::THINK_CHUNKS,
            $thoughts,
            'every reasoning chunk must reach the caller so it can be painted as it arrives',
        );
        $this->assertSame('think 0 ', $thoughts[0]);
    }

    /**
     * The family member with NOTHING to paint: chunks that carry only usage
     * figures (`VertexProvider`'s `message_start`). There is no reasoning text
     * to forward, so the ONLY thing these chunks can do is prove the turn is
     * alive — and they must still write a frame, or the deadline never moves.
     *
     * This is the case that the `content === ''` gate alone is responsible for:
     * the reasoning branch beside it cannot see a chunk with no reasoning in it.
     */
    public function testATurnWhoseChunksCarryOnlyUsageSurvivesTheIdleCeiling(): void
    {
        $this->requireFork();

        $thoughts = [];
        $tokens = [];
        $run = $this->runOnScaledClock(
            new StreamingDouble(self::THINK_CHUNKS, self::CHUNK_PAUSE_MICROS, 'usage', 'the answer'),
            $tokens,
            $thoughts,
        );

        $this->assertTurnSurvivedPastTheCeiling($run);
        $this->assertSame('the answer', $run['message']->content);
        $this->assertSame(
            [],
            $thoughts,
            'a usage-only chunk has nothing showable - it must move the deadline WITHOUT reaching the painter',
        );
    }

    /**
     * The third family member: chunks carrying only the structure of a tool
     * call. Same shape as the usage case — nothing to paint, everything to
     * prove.
     */
    public function testATurnWhoseChunksCarryOnlyToolStructureSurvivesTheIdleCeiling(): void
    {
        $this->requireFork();

        $thoughts = [];
        $tokens = [];
        $run = $this->runOnScaledClock(
            new StreamingDouble(self::THINK_CHUNKS, self::CHUNK_PAUSE_MICROS, 'toolstructure', 'the answer'),
            $tokens,
            $thoughts,
        );

        $this->assertTurnSurvivedPastTheCeiling($run);
        $this->assertSame('the answer', $run['message']->content);
        $this->assertSame([], $thoughts);
    }

    /**
     * The KNOWN-POSITIVE control for the three tests above (rule 15): the same
     * loop, the same scale, the same fork, and a provider that emits nothing at
     * all until it answers. That turn MUST be killed — otherwise the ceiling is
     * not being reached in this harness and every "it survived" above is a
     * measurement of a dead timer rather than of the fix.
     *
     * It also pins the property the fix must NOT have destroyed: a genuinely
     * silent provider still dies.
     */
    public function testASilentProviderIsStillKilledByTheSameCeilingOnTheSameClock(): void
    {
        $this->requireFork();

        $thoughts = [];
        $tokens = [];
        $run = $this->runOnScaledClock(
            new StreamingDouble(self::THINK_CHUNKS, self::CHUNK_PAUSE_MICROS, 'silent', 'the answer'),
            $tokens,
            $thoughts,
        );

        $this->assertFalse($run['realCeiling'], 'the harness ran out of real time instead of virtual time');
        $this->assertTrue($run['settled'], 'the promise never settled at all');
        $this->assertNotNull($run['error'], 'a provider that streamed nothing for the whole ceiling must be killed');
        $this->assertStringContainsString(
            'without progress',
            $run['error']->getMessage(),
            'the rejection must be the idle-timeout one, not some other failure',
        );
        $this->assertGreaterThan(
            $this->ceilingSeconds(),
            $run['virtualSeconds'],
            'the control did not actually reach the ceiling',
        );
    }

    // =====================================================================
    // the Runtime half, without a fork
    // =====================================================================

    /**
     * The conversation-corruption guard. Reasoning reaches `$onProgress` and
     * NOT `$onToken`, and — the part that matters — it is absent from the
     * {@see AssistantMessage} that the agentic loop feeds back to the model and
     * that the transcript checkpoints.
     */
    public function testAThoughtNeverEntersTheAssistantsOwnWords(): void
    {
        $tokens = [];
        $progress = [];

        $messages = iterator_to_array($this->runtime(
            new StreamingDouble(3, 0, 'reasoning', 'the answer'),
        )->run(
            $this->app(),
            null,
            null,
            static function (string $t) use (&$tokens): void { $tokens[] = $t; },
            static function (string $p) use (&$progress): void { $progress[] = $p; },
        ));

        $assistant = null;
        foreach ($messages as $message) {
            if ($message instanceof AssistantMessage) {
                $assistant = $message;
            }
        }

        // Located by class, and the class is asserted to EXIST first: a
        // mistyped FQN makes `instanceof` silently false, so this loop would
        // have found nothing and the failure would have read as a defect in
        // the code under test rather than in the test. (It did, once.)
        $this->assertTrue(class_exists(AssistantMessage::class), 'the roster class this test looks for is gone');
        $this->assertNotNull($assistant, 'the turn yielded no assistant message at all');
        $this->assertSame('the answer', $assistant->content());
        $this->assertStringNotContainsString('think 0', $assistant->content());
        $this->assertSame(['the answer'], $tokens);
        $this->assertSame(['think 0 ', 'think 1 ', 'think 2 '], $progress);
        // Reasoning is still ACCUMULATED onto the message for the transcript's
        // own collapsed think block - it is the assistant's WORDS it must stay
        // out of, not the message.
        $this->assertSame('think 0 think 1 think 2 ', $assistant->reasoning());
    }

    /**
     * A chunk carrying BOTH content and reasoning already moved the deadline
     * through `$onToken`, but its thinking still has to reach the screen.
     */
    public function testAChunkCarryingBothTextAndThinkingAnnouncesBoth(): void
    {
        $tokens = [];
        $progress = [];

        iterator_to_array($this->runtime(
            new StreamingDouble(0, 0, 'mixed', 'the answer'),
        )->run(
            $this->app(),
            null,
            null,
            static function (string $t) use (&$tokens): void { $tokens[] = $t; },
            static function (string $p) use (&$progress): void { $progress[] = $p; },
        ));

        $this->assertSame(['the answer'], $tokens);
        $this->assertSame(['thought alongside '], $progress);
    }

    /**
     * Uniformity with `$onToken`: a consumer painting live reasoning must not
     * need its own `supportsStreaming()` check to know whether any will arrive.
     * A batch provider has nothing incremental to offer, so it is the whole
     * think as one delta.
     *
     * This does NOT make a batch turn idle-timeout-proof and cannot — see the
     * DEFERRED note in {@see \SugarCraft\Crush\Runtime::runBatch()}.
     */
    public function testABatchProviderAnnouncesItsWholeThinkAsOneDelta(): void
    {
        $progress = [];

        iterator_to_array($this->runtime(new BatchDouble())->run(
            $this->app(),
            null,
            null,
            null,
            static function (string $p) use (&$progress): void { $progress[] = $p; },
        ));

        $this->assertSame(['thought it through'], $progress);
    }

    /**
     * The channel is OPTIONAL end to end. Nobody has to pass one for the timer
     * fix to work — the frame is written by the child and the deadline is reset
     * by the parent whether or not the caller is listening — so every existing
     * four-argument caller must keep working untouched.
     */
    public function testTheProgressChannelIsOptional(): void
    {
        $tokens = [];

        $messages = iterator_to_array($this->runtime(
            new StreamingDouble(3, 0, 'reasoning', 'the answer'),
        )->run(
            $this->app(),
            null,
            null,
            static function (string $t) use (&$tokens): void { $tokens[] = $t; },
        ));

        $this->assertSame(['the answer'], $tokens);
        $this->assertNotSame([], $messages);
    }

    // =====================================================================
    // fixtures
    // =====================================================================

    private function requireFork(): void
    {
        // An ungated arm is not available for these three - they measure a
        // forked child's socket. Named explicitly rather than copied from a
        // neighbouring gate: both functions were checked on this host
        // (PHP 8.3.6) and both exist, so this gate does NOT fire here and the
        // tests really run.
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            self::markTestSkipped('completeAsync() falls back to a blocking, timer-less path without pcntl');
        }
    }

    private function ceilingSeconds(): float
    {
        // Read off the constant rather than spelled as 120: a future change to
        // the ceiling must not silently turn these tests into assertions about
        // a number nothing uses.
        return (float) (new \ReflectionClass(EngineBackend::class))
            ->getReflectionConstant('COMPLETE_TIMEOUT_SECONDS')
            ->getValue();
    }

    /**
     * @param array<int, string> $tokens
     * @param array<int, string> $thoughts
     *
     * @return array{settled: bool, message: ?Message, error: ?\Throwable, virtualSeconds: float, realCeiling: bool}
     */
    private function runOnScaledClock(ProviderInterface $provider, array &$tokens, array &$thoughts): array
    {
        $backend = EngineBackend::new($provider, 'scaled');

        $loop = new ScaledClockLoop();
        $this->previousLoop = Loop::get();
        Loop::set($loop);

        $settled = false;
        $value = null;
        $error = null;

        try {
            $promise = $backend->completeAsync(
                [Message::user('think about it')],
                static function (string $t) use (&$tokens): void { $tokens[] = $t; },
                null,
                null,
                static function (string $d) use (&$thoughts): void { $thoughts[] = $d; },
            );

            $this->settleOn($promise, $loop, $settled, $value, $error);
        } finally {
            Loop::set($this->previousLoop);
            $this->previousLoop = null;
        }

        return [
            'settled' => $settled,
            'message' => $value instanceof Message ? $value : null,
            'error' => $error,
            'virtualSeconds' => $loop->highWaterVirtualSeconds(),
            'realCeiling' => $loop->hitRealCeiling(),
        ];
    }

    private function settleOn(
        PromiseInterface $promise,
        ScaledClockLoop $loop,
        bool &$settled,
        mixed &$value,
        ?\Throwable &$error,
    ): void {
        $promise->then(
            static function ($v) use (&$settled, &$value, $loop): void {
                $settled = true;
                $value = $v;
                $loop->stop();
            },
            static function (\Throwable $e) use (&$settled, &$error, $loop): void {
                $settled = true;
                $error = $e;
                $loop->stop();
            },
        );

        // A single run()/stop() pair, matching EngineBackendTest::awaitPromise()
        // and Program::run() itself. The bound is ScaledClockLoop's own REAL
        // ceiling, not an addTimer() - a timer here would be measured on the
        // scaled clock and would fire in milliseconds.
        if (!$settled) {
            $loop->run();
        }
    }

    /** @param array{settled: bool, message: ?Message, error: ?\Throwable, virtualSeconds: float, realCeiling: bool} $run */
    private function assertTurnSurvivedPastTheCeiling(array $run): void
    {
        $this->assertFalse(
            $run['realCeiling'],
            'the harness ran out of REAL time - nothing below is a verdict about the idle ceiling',
        );
        if ($run['error'] !== null) {
            $this->fail('the turn was killed: ' . $run['error']->getMessage());
        }
        $this->assertTrue($run['settled'], 'the promise never settled');
        $this->assertNotNull($run['message'], 'the turn settled without a reply');
        // The whole point: the loop's clock genuinely went past the ceiling
        // while the turn was in flight. Without this the three tests above
        // would pass on a build where the timer never got close.
        $this->assertGreaterThan(
            $this->ceilingSeconds(),
            $run['virtualSeconds'],
            'the clock never reached the idle ceiling, so surviving it proves nothing',
        );
    }

    private function runtime(ProviderInterface $provider): Runtime
    {
        return new Runtime($provider, new HookManager(new HookRegistry()));
    }

    private function app(): App
    {
        return App::new(new BatchDouble(), 'double');
    }
}

/**
 * A `LoopInterface` whose STREAMS are real and whose CLOCK is scaled.
 *
 * `stream_select()` runs for real against the real socket pair
 * {@see EngineBackend::completeAsync()} creates, so frames arrive from a real
 * forked child in real order. Only `addTimer()`/`addPeriodicTimer()` are
 * re-based: a timer's deadline is compared against
 * `real elapsed x VIRTUAL_SECONDS_PER_REAL_SECOND`, so the 120-second idle
 * ceiling the code arms unchanged is crossed in 240 ms of wall time.
 *
 * Two properties this deliberately keeps:
 *
 *   - `run()` has its own REAL ceiling, so a regression that stops the promise
 *     settling fails the test rather than hanging the suite;
 *   - `addSignal()` THROWS rather than no-opping. A silently dropped signal
 *     handler would be a behaviour this loop quietly removed from the code
 *     under test; nothing in `completeAsync()` registers one today, and if that
 *     changes the test must go red rather than lie.
 */
final class ScaledClockLoop implements LoopInterface
{
    /**
     * Virtual seconds per real second. 500 puts a 120-second ceiling 240 ms of
     * wall time away, which is far enough from a 20 ms inter-chunk gap
     * (~10 virtual seconds) that a slow host cannot turn a surviving turn into
     * a killed one, and near enough that a `usleep()`-bounded silence is a
     * guaranteed crossing.
     */
    public const VIRTUAL_SECONDS_PER_REAL_SECOND = 500.0;

    /** Wall-clock backstop so a never-settling promise fails instead of hanging. */
    private const REAL_CEILING_SECONDS = 25.0;

    private const SELECT_MICROS = 200;

    private float $startNs;

    private \SplObjectStorage $timers;

    /** @var array<int, array{0: resource, 1: callable}> */
    private array $readStreams = [];

    /** @var array<int, array{0: resource, 1: callable}> */
    private array $writeStreams = [];

    /** @var list<callable> */
    private array $ticks = [];

    private bool $stopped = false;

    private bool $realCeiling = false;

    private float $highWater = 0.0;

    public function __construct()
    {
        $this->startNs = (float) hrtime(true);
        $this->timers = new \SplObjectStorage();
    }

    /** Virtual seconds since this loop was constructed. */
    public function now(): float
    {
        $seconds = ((float) hrtime(true) - $this->startNs) / 1e9 * self::VIRTUAL_SECONDS_PER_REAL_SECOND;
        if ($seconds > $this->highWater) {
            $this->highWater = $seconds;
        }

        return $seconds;
    }

    public function highWaterVirtualSeconds(): float
    {
        return $this->highWater;
    }

    public function hitRealCeiling(): bool
    {
        return $this->realCeiling;
    }

    public function addReadStream($stream, $listener): void
    {
        $this->readStreams[(int) $stream] = [$stream, $listener];
    }

    public function addWriteStream($stream, $listener): void
    {
        $this->writeStreams[(int) $stream] = [$stream, $listener];
    }

    public function removeReadStream($stream): void
    {
        unset($this->readStreams[(int) $stream]);
    }

    public function removeWriteStream($stream): void
    {
        unset($this->writeStreams[(int) $stream]);
    }

    public function addTimer($interval, $callback): TimerInterface
    {
        $timer = new Timer($interval, $callback, false);
        $this->timers->attach($timer, $this->now() + (float) $interval);

        return $timer;
    }

    public function addPeriodicTimer($interval, $callback): TimerInterface
    {
        $timer = new Timer($interval, $callback, true);
        $this->timers->attach($timer, $this->now() + (float) $interval);

        return $timer;
    }

    public function cancelTimer(TimerInterface $timer): void
    {
        $this->timers->detach($timer);
    }

    public function futureTick($listener): void
    {
        $this->ticks[] = $listener;
    }

    public function addSignal($signal, $listener): void
    {
        throw new \LogicException(
            'ScaledClockLoop has no signal support and will not pretend to: the code under test registered '
            . 'signal ' . $signal . ', which this loop would silently drop.',
        );
    }

    public function removeSignal($signal, $listener): void
    {
        throw new \LogicException('ScaledClockLoop has no signal support; see addSignal().');
    }

    public function run(): void
    {
        $this->stopped = false;
        $realDeadline = microtime(true) + self::REAL_CEILING_SECONDS;

        while (!$this->stopped) {
            foreach ($this->drainTicks() as $tick) {
                $tick();
                if ($this->stopped) {
                    return;
                }
            }

            $this->fireDueTimers();
            if ($this->stopped) {
                return;
            }

            if (microtime(true) >= $realDeadline) {
                $this->realCeiling = true;

                return;
            }

            $hasStreams = $this->readStreams !== [] || $this->writeStreams !== [];
            if (!$hasStreams && $this->timers->count() === 0 && $this->ticks === []) {
                return;
            }

            if ($hasStreams) {
                $this->pollStreams();
            } else {
                usleep(self::SELECT_MICROS);
            }

            $this->now();
        }
    }

    public function stop(): void
    {
        $this->stopped = true;
    }

    /** @return list<callable> */
    private function drainTicks(): array
    {
        $ticks = $this->ticks;
        $this->ticks = [];

        return $ticks;
    }

    private function pollStreams(): void
    {
        $read = array_column($this->readStreams, 0);
        $write = array_column($this->writeStreams, 0);
        $except = [];

        if (@stream_select($read, $write, $except, 0, self::SELECT_MICROS) < 1) {
            return;
        }

        foreach ($read as $stream) {
            $key = (int) $stream;
            if (isset($this->readStreams[$key])) {
                ($this->readStreams[$key][1])($stream, $this);
            }
            if ($this->stopped) {
                return;
            }
        }
        foreach ($write as $stream) {
            $key = (int) $stream;
            if (isset($this->writeStreams[$key])) {
                ($this->writeStreams[$key][1])($stream, $this);
            }
            if ($this->stopped) {
                return;
            }
        }
    }

    private function fireDueTimers(): void
    {
        $now = $this->now();

        foreach (iterator_to_array($this->timers, false) as $timer) {
            if (!$this->timers->contains($timer)) {
                continue;
            }
            if ((float) $this->timers[$timer] > $now) {
                continue;
            }
            if ($timer->isPeriodic()) {
                $this->timers[$timer] = $now + $timer->getInterval();
            } else {
                $this->timers->detach($timer);
            }

            ($timer->getCallback())($timer);

            if ($this->stopped) {
                return;
            }
        }
    }
}

/**
 * A streaming provider whose pre-answer chunks all carry `content: ''` — one
 * shape per family member, plus a `silent` shape that emits nothing at all and
 * is the known-positive control.
 */
final class StreamingDouble implements ProviderInterface
{
    public function __construct(
        private int $chunks,
        private int $pauseMicros,
        private string $shape,
        private string $answer,
    ) {}

    public function name(): string { return 'double'; }
    public function supportsStreaming(): bool { return true; }
    public function supportsFunctionCalling(): bool { return false; }
    public function supportsVision(): bool { return false; }
    public function supportsJsonSchema(): bool { return false; }
    public function contextWindow(): int { return 1000; }
    public function costPer1kTokens(string $model, string $direction): float { return 0.0; }

    public function complete(CompleteRequest $request): CompleteResponse
    {
        return new CompleteResponse(content: $this->answer);
    }

    public function completeStream(CompleteRequest $request): \Generator
    {
        if ($this->shape === 'mixed') {
            yield new CompleteResponse(content: $this->answer, reasoning: 'thought alongside ');

            return;
        }

        for ($i = 0; $i < $this->chunks; $i++) {
            if ($this->pauseMicros > 0) {
                // A guaranteed LOWER bound on real elapsed time, which is what
                // makes "the clock crossed the ceiling" unflakeable.
                usleep($this->pauseMicros);
            }
            if ($this->shape === 'silent') {
                continue;
            }

            yield match ($this->shape) {
                'reasoning' => new CompleteResponse(content: '', reasoning: 'think ' . $i . ' '),
                'usage' => new CompleteResponse(content: '', tokensUsed: 1),
                'toolstructure' => new CompleteResponse(content: '', toolCalls: []),
                default => throw new \LogicException('unknown shape ' . $this->shape),
            };
        }

        yield new CompleteResponse(content: $this->answer, tokensUsed: 7);
    }

    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
    {
        return new EmbeddingsResponse([]);
    }
}

/** A non-streaming provider that thinks and then answers in one call. */
final class BatchDouble implements ProviderInterface
{
    public function name(): string { return 'batch'; }
    public function supportsStreaming(): bool { return false; }
    public function supportsFunctionCalling(): bool { return false; }
    public function supportsVision(): bool { return false; }
    public function supportsJsonSchema(): bool { return false; }
    public function contextWindow(): int { return 1000; }
    public function costPer1kTokens(string $model, string $direction): float { return 0.0; }

    public function complete(CompleteRequest $request): CompleteResponse
    {
        return new CompleteResponse(content: 'the answer', reasoning: 'thought it through');
    }

    public function completeStream(CompleteRequest $request): \Generator
    {
        yield new CompleteResponse(content: 'the answer');
    }

    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
    {
        return new EmbeddingsResponse([]);
    }
}

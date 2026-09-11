<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Events\SpendCapBreached;
use SugarCraft\Crush\Events\ToolFinished;
use SugarCraft\Crush\Events\ToolStarted;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\EmbeddingsRequest;
use SugarCraft\Crush\Providers\EmbeddingsResponse;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * E20's remaining half: the mid-turn ABORT. `maxCostUsd` previously only
 * refused turns BEFORE dispatch ({@see \SugarCraft\Crush\Chat}'s
 * spendCapRefusal path) — an agentic turn already in flight could run all
 * eight steps and bill far past the cap because nothing between the provider
 * calls ever looked at what the session had spent.
 *
 * The fix is a boundary check, NOT a timer: `complete()` runs a bounded loop
 * of provider calls, and after each call whose tools produced results — i.e.
 * exactly when the loop is about to place ANOTHER call — it adds the session
 * spend the turn inherited plus every step billed so far and, at or over the
 * cap, stops: no wall-clock kill, no total-request timeout (both standing
 * bans), only "do not send the next request", enforced even in the forked
 * child because the cap travels INTO the child as a copy (see the
 * constructor's own docblock).
 *
 * Every test here asserts the refusal through behaviour — the provider's call
 * counter is the ground truth of "the next request was never sent" — because
 * a test that only read the event would stay green with a cap that reports
 * but never stops.
 */
final class EngineBackendSpendCapTest extends TestCase
{
    /**
     * A provider that bills $costPerCall and always asks for the `clock` tool.
     * Left uncapped this loop runs to maxSteps; every cap test below asserts it
     * does NOT — the call counter pinning "the next provider call never ran".
     */
    private function billingProvider(float $costPerCall, int $tokensPerCall = 100): ProviderInterface
    {
        return new class($costPerCall, $tokensPerCall) implements ProviderInterface {
            public int $calls = 0;

            public function __construct(private readonly float $cost, private readonly int $tokens) {}

            public function name(): string { return 'biller'; }
            public function supportsStreaming(): bool { return false; }
            public function supportsFunctionCalling(): bool { return true; }
            public function supportsVision(): bool { return false; }
            public function supportsJsonSchema(): bool { return false; }
            public function contextWindow(): int { return 100_000; }
            public function costPer1kTokens(string $model, string $direction): float { return 0.0; }

            public function complete(CompleteRequest $request): CompleteResponse
            {
                $this->calls++;

                return new CompleteResponse(
                    content: 'checking',
                    toolCalls: [new ToolCall('call_' . $this->calls, 'clock', [])],
                    tokensUsed: $this->tokens,
                    costUsd: $this->cost,
                );
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

    /** A provider that answers immediately with prose — a turn that finishes on its own. */
    private function answeringProvider(float $cost): ProviderInterface
    {
        return new class($cost) implements ProviderInterface {
            public int $calls = 0;

            public function __construct(private readonly float $cost) {}

            public function name(): string { return 'answerer'; }
            public function supportsStreaming(): bool { return false; }
            public function supportsFunctionCalling(): bool { return true; }
            public function supportsVision(): bool { return false; }
            public function supportsJsonSchema(): bool { return false; }
            public function contextWindow(): int { return 100_000; }
            public function costPer1kTokens(string $model, string $direction): float { return 0.0; }

            public function complete(CompleteRequest $request): CompleteResponse
            {
                $this->calls++;

                return new CompleteResponse(
                    content: 'here is what I found',
                    tokensUsed: 40,
                    costUsd: $this->cost,
                );
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

    /** The cheapest tool that keeps the agentic loop wanting another step. */
    private function clockTool(): Tool
    {
        return new class implements Tool {
            public function name(): string { return 'clock'; }
            public function description(): string { return 'test tool'; }
            public function inputSchema(): array { return []; }
            public function execute(array $args): ToolResult
            {
                return new ToolResult(toolCallId: '', content: 'NOON');
            }
        };
    }

    /** @return list<ToolStarted|ToolFinished|SpendCapBreached> */
    private function captureEvents(?array &$events): \Closure
    {
        $events = [];

        return static function (object $event) use (&$events): void {
            $events[] = $event;
        };
    }

    private function cappedBackend(float $cap, float $baseline = 0.0, ?ProviderInterface $provider = null): EngineBackend
    {
        return EngineBackend::new($provider ?? $this->billingProvider(1.2), 'billed')
            ->withTools([$this->clockTool()])
            ->withSpendCap($cap, $baseline);
    }

    // =====================================================================
    // The boundary check itself
    // =====================================================================

    public function testAStepBoundaryAtOrPastTheCapNeverPlacesTheNextProviderCall(): void
    {
        $provider = $this->billingProvider(1.2);
        $backend = $this->cappedBackend(1.0, provider: $provider);

        $onEvent = $this->captureEvents($captured);
        $reply = $backend->complete([Message::user('go')], null, $onEvent);

        $this->assertSame(
            1,
            $provider->calls,
            'step 1 billed $1.20 against a $1.00 cap — the SECOND provider call is the spend the cap exists to prevent, so it must never happen',
        );
        $this->assertSame(
            'checking',
            $reply->content,
            'the turn returns the work that DID run, not an error — this is an abort, not a failure',
        );

        $breach = array_filter($captured, static fn (object $e): bool => $e instanceof SpendCapBreached);
        $this->assertCount(1, $breach, 'exactly one breach per aborted turn');
        $breach = array_values($breach)[0];
        $this->assertSame(1, $breach->completedCalls, 'one provider call completed before the boundary refused the next');
        $this->assertEqualsWithDelta(1.2, $breach->spentUsd, 0.000001);
        $this->assertEqualsWithDelta(1.0, $breach->capUsd, 0.000001);
        $this->assertSame(
            $captured[array_key_last($captured)],
            $breach,
            'the breach is the LAST event — after the started/finished pair of the tool whose results were already paid for',
        );
    }

    public function testATurnBelowTheCapRunsItsWholeLoopUnbothered(): void
    {
        $provider = $this->billingProvider(0.4);
        $backend = $this->cappedBackend(50.0, provider: $provider);
        $onEvent = $this->captureEvents($captured);

        $reply = $backend->withMaxSteps(3)->complete([Message::user('go')], null, $onEvent);

        $this->assertSame(3, $provider->calls, 'an in-budget agentic turn must not lose a step to the mere existence of a cap');
        $this->assertSame('checking', $reply->content);
        $this->assertSame(
            [],
            array_filter($captured, static fn (object $e): bool => $e instanceof SpendCapBreached),
            'no breach may be reported when the boundary was never crossed',
        );
    }

    public function testTheSessionBaselineCountsTowardTheBoundary(): void
    {
        // The cap is SESSION spend, not turn spend: a session that already
        // spent $0.90 of a $1.00 cap is crossed by THIS turn's first step at
        // $0.20 even though the turn alone is nowhere near the number.
        $provider = $this->billingProvider(0.2);
        $backend = $this->cappedBackend(1.0, 0.9, provider: $provider);
        $onEvent = $this->captureEvents($captured);

        $backend->complete([Message::user('go')], null, $onEvent);

        $this->assertSame(1, $provider->calls, 'baseline $0.90 + step $0.20 >= $1.00 — the second call is out of session budget even though this turn spent little');
        $breach = array_values(array_filter($captured, static fn (object $e): bool => $e instanceof SpendCapBreached));
        $this->assertCount(1, $breach);
        $this->assertEqualsWithDelta(1.1, $breach[0]->spentUsd, 0.000001, 'the reported figure is baseline + what the turn billed');
    }

    public function testEqualityWithTheCapBreachesTheSameAsOverspendingIt(): void
    {
        // Same `>=` semantics as Chat::spendCapReached()/spendCapRefusal at
        // the START gate — the boundary must not be looser than the gate, or
        // "cap reached" means two different numbers in one feature.
        $provider = $this->billingProvider(1.0);
        $backend = $this->cappedBackend(1.0, provider: $provider);

        $backend->complete([Message::user('go')], null, $this->captureEvents($captured));

        $this->assertSame(1, $provider->calls, 'spent exactly AT the cap is spent up — the next call is refused');
        $this->assertCount(1, array_filter($captured, static fn (object $e): bool => $e instanceof SpendCapBreached));
    }

    public function testATurnThatFinishedOnItsOwnIsNeverRetroactivelyBreached(): void
    {
        // The check sits AFTER the "no tool results → break" exit precisely so
        // a completed answer cannot be reported as an aborted one: over-spending
        // on the FINAL call is a billing fact the user sees in /budget, not a
        // refusal the transcript should pretend happened.
        $provider = $this->answeringProvider(5.0);
        $backend = $this->cappedBackend(1.0, provider: $provider);
        $onEvent = $this->captureEvents($captured);

        $reply = $backend->complete([Message::user('go')], null, $onEvent);

        $this->assertSame(1, $provider->calls);
        $this->assertSame('here is what I found', $reply->content);
        $this->assertSame([], array_filter($captured, static fn (object $e): bool => $e instanceof SpendCapBreached));
    }

    public function testTheBreachedTurnIsBilledForExactlyTheCallsThatRan(): void
    {
        $provider = $this->billingProvider(1.2, tokensPerCall: 100);
        $backend = $this->cappedBackend(1.0, provider: $provider);

        $reply = $backend->complete([Message::user('go')], null, $this->captureEvents($captured));

        $this->assertNotNull($reply->usage);
        $this->assertEqualsWithDelta(
            1.2,
            $reply->usage->costUsd,
            0.000001,
            'the one call that ran really billed $1.20 — aborting the loop must not erase the debt, or the session start-gate would let the NEXT turn run on a phantom-cheap ledger',
        );
        $this->assertSame(100, $reply->usage->totalTokens);
    }

    // =====================================================================
    // The clone that carries it
    // =====================================================================

    public function testWithSpendCapReturnsAFreshCarryingBothFiguresWithoutTouchingTheOriginal(): void
    {
        $backend = EngineBackend::new($this->billingProvider(1.2), 'billed');

        $capped = $backend->withSpendCap(0.75, 0.25);

        $this->assertNotSame($backend, $capped);
        $this->assertNull($this->readProperty($backend, 'spendCapUsd'), 'an un-capped backend stays un-capped — the copy must not leak back');
        $this->assertSame(0.0, $this->readProperty($backend, 'sessionSpendAtStartUsd'));
        $this->assertSame(0.75, $this->readProperty($capped, 'spendCapUsd'));
        $this->assertSame(0.25, $this->readProperty($capped, 'sessionSpendAtStartUsd'));

        $nullAgain = $capped->withSpendCap(null);
        $this->assertNull($this->readProperty($nullAgain, 'spendCapUsd'), 'passing null clears the cap (the /budget off route)');
        $this->assertSame(0.0, $this->readProperty($nullAgain, 'sessionSpendAtStartUsd'), 'a cap-less carry has no reason to remember a baseline');
    }

    public function testTheCapSurvivesTheOtherFluentClones(): void
    {
        // Each `new self(...)` in this class is a place the cap could silently
        // drop — withMaxSteps is the one the agentic TUI chain composes over a
        // capped backend, so pin it (and tools, the PreToolUse-heavy one).
        $capped = $this->cappedBackend(1.0);

        $after = $capped->withMaxSteps(4);
        $this->assertSame(1.0, $this->readProperty($after, 'spendCapUsd'), 'withMaxSteps dropping the cap would silently un-limit every /workflow turn');
        $this->assertSame(0.0, $this->readProperty($after, 'sessionSpendAtStartUsd'));

        $withTools = $capped->withTools([$this->clockTool()]);
        $this->assertSame(1.0, $this->readProperty($withTools, 'spendCapUsd'));
    }

    // =====================================================================
    // The fork boundary — the cap must be enforced in the CHILD
    // =====================================================================

    public function testTheBreachCrossesTheForkBoundaryAsAnEventAndABilledReply(): void
    {
        $provider = $this->billingProvider(1.2);
        $backend = $this->cappedBackend(1.0, provider: $provider);
        $onEvent = $this->captureEvents($captured);

        // The parent cannot see the child's $provider->calls — it is copy-on-
        // write — so "the second call never happened" is proven HERE by the
        // two parent-visible halves: the billed usage (exactly one step) and
        // the content (the tool-hungry step, never a later answer).
        $reply = $this->awaitPromise($backend->completeAsync([Message::user('go')], null, null, $onEvent));

        $breach = array_values(array_filter($captured, static fn (object $e): bool => $e instanceof SpendCapBreached));
        $this->assertCount(1, $breach, 'the spend_cap frame must decode into the same event class the sync path delivers');
        $this->assertSame(1, $breach[0]->completedCalls);
        $this->assertEqualsWithDelta(1.2, $breach[0]->spentUsd, 0.000001);
        $this->assertEqualsWithDelta(1.0, $breach[0]->capUsd, 0.000001);

        $this->assertNotNull($reply->usage);
        $this->assertEqualsWithDelta(1.2, $reply->usage->costUsd, 0.000001, 'the result frame bills the child\'s one executed call across the socket');
        $this->assertSame('checking', $reply->content);
    }

    // =====================================================================
    // The wire codec arms
    // =====================================================================

    public function testTheBreachRoundTripsThroughTheFrameCodec(): void
    {
        $encoded = $this->invokeStatic('encodeEvent', [new SpendCapBreached(3, 0.25, 1.5)]);

        $this->assertSame('spend_cap', $encoded['kind']);
        $decoded = $this->invokeStatic('decodeEvent', [$encoded]);
        $this->assertInstanceOf(SpendCapBreached::class, $decoded);
        $this->assertSame(3, $decoded->completedCalls);
        $this->assertEqualsWithDelta(0.25, $decoded->spentUsd, 0.000001);
        $this->assertEqualsWithDelta(1.5, $decoded->capUsd, 0.000001);
    }

    public function testTheCodecRefusesAMalformedSpendCapFrame(): void
    {
        // decodeEvent's contract for anything it did not write is null — the
        // parent drops the frame, not the turn.
        $this->assertNull($this->invokeStatic('decodeEvent', [['kind' => 'spend_cap', 'calls' => 'three', 'spent' => 0.25, 'cap' => 1.5]]));
        $this->assertNull($this->invokeStatic('decodeEvent', [['kind' => 'spend_cap', 'calls' => 3, 'spent' => 0.25]]));
    }

    // =====================================================================
    // helpers
    // =====================================================================

    private function readProperty(object $subject, string $name): mixed
    {
        $property = new \ReflectionProperty($subject, $name);
        $property->setAccessible(true);

        return $property->getValue($subject);
    }

    /** @param list<mixed> $args */
    private function invokeStatic(string $method, array $args): mixed
    {
        $reflection = new \ReflectionMethod(EngineBackend::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs(null, $args);
    }

    /**
     * Pump the shared ReactPHP loop until the forked child settles — the same
     * one-shot run()/stop() shape `EngineBackendTest::awaitPromise()` uses,
     * copied rather than referenced because that helper is private to its
     * class and this file must not couple to its fixture.
     */
    private function awaitPromise(PromiseInterface $promise): mixed
    {
        $loop = Loop::get();
        $settled = false;
        $value = null;
        $error = null;

        $promise->then(
            function ($v) use (&$settled, &$value, $loop): void {
                $settled = true;
                $value = $v;
                $loop->stop();
            },
            function (\Throwable $e) use (&$settled, &$error, $loop): void {
                $settled = true;
                $error = $e;
                $loop->stop();
            },
        );

        if (!$settled) {
            $timer = $loop->addTimer(60.0, static function () use ($loop, &$settled): void {
                $settled = true;
                $loop->stop();
            });
            $loop->run();
            $loop->cancelTimer($timer);
        }

        if ($error !== null) {
            throw $error;
        }
        $this->assertTrue($settled, 'the forked turn never settled — completeAsync() hung or its socket frame was dropped');

        return $value;
    }
}

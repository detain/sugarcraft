<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Integration;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Agents\SubAgent;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\EmbeddingsRequest;
use SugarCraft\Crush\Providers\EmbeddingsResponse;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Providers\TransientFailure;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Behavioural pins for the crush_code.md Phase 5 item 8 retry.
 *
 * The tests that matter here are the ones that would still pass if the retry
 * were wired wrongly in a plausible way, and therefore have to be written to
 * fail in that case:
 *
 *   - {@see testARetriedTurnDoesNotReRunToolCallsThatAlreadySucceeded()} is why
 *     the retry is NOT where the plan put it.
 *   - {@see testAStreamThatFailsAfterEmittingToAConsumerIsNotRetried()} and
 *     {@see testASinklessStreamRetryDoesNotDuplicateTheReply()} are the two
 *     halves of the streaming policy; either one alone would let the other's
 *     bug through.
 *   - {@see testARetryDoesNotDoubleCountUsage()} covers the Bundle B2
 *     interaction, where these figures started driving a spend cap.
 */
final class ProviderRetryWiringTest extends TestCase
{
    private static function transient(): ServerException
    {
        return new ServerException(
            '503 Service Unavailable',
            new Request('POST', 'https://example.invalid/v1'),
            new Response(503),
        );
    }

    private static function permanent(): ClientException
    {
        return new ClientException(
            '401 Unauthorized',
            new Request('POST', 'https://example.invalid/v1'),
            new Response(401),
        );
    }

    private static function runtime(ProviderInterface $provider): Runtime
    {
        return new Runtime($provider, new HookManager(new HookRegistry()));
    }

    private static function app(ProviderInterface $provider, array $tools = []): App
    {
        return App::new($provider, 'test-model')
            ->withTools($tools)
            ->withMessages([new UserMessage('go')]);
    }

    // -------------------------------------------------------------------------
    // The reason the retry is at the provider call and not around the loop.
    // -------------------------------------------------------------------------

    /**
     * THE regression test for the plan's proposed location.
     *
     * crush_code.md Phase 5 item 8 says to put the retry inside
     * `EngineBackend::runCompleteInChild()`, which wraps
     * {@see EngineBackend::complete()} — the whole bounded agentic loop, tool
     * dispatch included. A retry there replays every tool call the failed
     * attempt already ran.
     *
     * The scenario is the one that distinguishes the two placements: step 1's
     * provider call succeeds and asks for a tool, the tool runs, and then step
     * 2's provider call fails transiently. A retry at the provider seam re-issues
     * only step 2. A retry around the loop would re-issue step 1 as well and run
     * the tool a SECOND time — so the tool's execution count is what tells the
     * two apart, and it must stay at 1.
     */
    public function testARetriedTurnDoesNotReRunToolCallsThatAlreadySucceeded(): void
    {
        $tool = new CountingTool();
        $provider = new ScriptedProvider([
            // step 1: ask for the tool
            ScriptedAttempt::chunks(['', ''], toolCalls: [new ToolCall('call-1', 'counter', [])]),
            // step 2: transient failure
            ScriptedAttempt::throws(self::transient()),
            // step 2 retried: the real answer
            ScriptedAttempt::chunks(['done']),
        ]);

        $backend = (new EngineBackend($provider, 'test-model'))->withTools([$tool]);
        $message = $backend->complete([]);

        $this->assertSame('done', $message->content);
        $this->assertSame(3, $provider->attempts(), 'three provider calls: two steps plus one retry');
        $this->assertSame(
            1,
            $tool->executions(),
            'the tool ran once and must not be replayed by the retry - a retry around the agentic '
            . 'loop is what would run it twice',
        );
    }

    // -------------------------------------------------------------------------
    // Batch path. Nothing observable happens before failure, so retry is
    // unconditional.
    // -------------------------------------------------------------------------

    public function testABatchTransientFailureIsRetriedAndSucceeds(): void
    {
        $provider = new ScriptedProvider([
            ScriptedAttempt::throws(self::transient()),
            ScriptedAttempt::batch('recovered'),
        ], streams: false);

        $messages = iterator_to_array(self::runtime($provider)->run(self::app($provider)));

        $this->assertSame(2, $provider->attempts());
        $this->assertInstanceOf(AssistantMessage::class, $messages[0]);
        $this->assertSame('recovered', $messages[0]->content());
    }

    public function testABatchPermanentFailureIsNotRetried(): void
    {
        $provider = new ScriptedProvider([
            ScriptedAttempt::throws(self::permanent()),
            ScriptedAttempt::batch('never reached'),
        ], streams: false);

        try {
            iterator_to_array(self::runtime($provider)->run(self::app($provider)));
            $this->fail('a 401 must propagate, not be retried');
        } catch (ClientException $e) {
            $this->assertSame(401, $e->getResponse()->getStatusCode());
        }

        $this->assertSame(1, $provider->attempts(), 'a permanent failure costs exactly one call');
    }

    public function testAnExhaustedBatchRetrySequenceThrowsTheLastFailure(): void
    {
        $attempts = array_fill(0, TransientFailure::MAX_ATTEMPTS + 2, ScriptedAttempt::throws(self::transient()));
        $provider = new ScriptedProvider($attempts, streams: false);

        $this->expectException(ServerException::class);

        try {
            iterator_to_array(self::runtime($provider)->run(self::app($provider)));
        } finally {
            $this->assertSame(
                TransientFailure::MAX_ATTEMPTS,
                $provider->attempts(),
                'the ceiling is MAX_ATTEMPTS calls, derived rather than a literal',
            );
        }
    }

    /**
     * The second failure channel: Vertex and Custom report a failed call as an
     * `isError` response instead of throwing. A retry that only caught
     * exceptions would leave both of them uncovered.
     */
    public function testABatchTransientErrorResponseIsRetried(): void
    {
        $provider = new ScriptedProvider([
            ScriptedAttempt::response(new CompleteResponse(
                content: '',
                isError: true,
                errorMessage: 'overloaded',
                errorTransient: true,
            )),
            ScriptedAttempt::batch('recovered'),
        ], streams: false);

        $messages = iterator_to_array(self::runtime($provider)->run(self::app($provider)));

        $this->assertSame(2, $provider->attempts());
        $this->assertSame('recovered', $messages[0]->content());
    }

    public function testABatchUnclassifiedErrorResponseIsNotRetried(): void
    {
        // errorTransient left null - the allow-list rule reaching the wiring.
        $provider = new ScriptedProvider([
            ScriptedAttempt::response(new CompleteResponse(
                content: '',
                isError: true,
                errorMessage: 'who knows',
            )),
            ScriptedAttempt::batch('never reached'),
        ], streams: false);

        $messages = iterator_to_array(self::runtime($provider)->run(self::app($provider)));

        $this->assertSame(1, $provider->attempts());
        $this->assertSame('', $messages[0]->content(), 'and the pre-retry outcome is unchanged');
    }

    // -------------------------------------------------------------------------
    // Streaming policy. Gated on whether a byte reached the token sink.
    // -------------------------------------------------------------------------

    /**
     * The emit-then-fail-then-retry case, with a sink attached: NOT retried.
     *
     * `$onToken` paints straight into the transcript and there is no un-emit, so
     * restarting the stream would show the user the reply twice. The docblock on
     * {@see Runtime::runStreaming()} says a mid-stream failure is not retried
     * when a sink is attached; this is that clause asserted rather than stated.
     */
    public function testAStreamThatFailsAfterEmittingToAConsumerIsNotRetried(): void
    {
        $provider = new ScriptedProvider([
            ScriptedAttempt::chunksThenThrow(['Hel', 'lo'], self::transient()),
            ScriptedAttempt::chunks(['Hello world']),
        ]);

        $seen = [];
        $sink = static function (string $delta) use (&$seen): void {
            $seen[] = $delta;
        };

        try {
            iterator_to_array(self::runtime($provider)->run(self::app($provider), null, null, $sink));
            $this->fail('a mid-stream failure after visible text must propagate');
        } catch (ServerException) {
            // expected
        }

        $this->assertSame(1, $provider->attempts(), 'no retry once bytes have been emitted');
        $this->assertSame(['Hel', 'lo'], $seen, 'and the user saw the partial reply exactly once');
    }

    /**
     * The same policy on the ERROR-RESPONSE channel, which is the half that
     * matters for the provider this feature was built for.
     *
     * Vertex's transient signal is not a throw: an overloaded
     * Anthropic-on-Vertex backend opens a 200 SSE stream and emits an `error`
     * event mid-stream, which `parseAnthropicChunk()` turns into an `isError`
     * chunk. So the emit-then-fail case arrives through the error-chunk gate,
     * not the throw gate, and only the throw gate was pinned: deleting
     * `|| $emitted` from the error-chunk gate was measured green across 3188
     * tests, and with real backoff it took the suite from 2m26s to over 10m —
     * many tests stream error chunks and none of them counted attempts.
     *
     * Without the guard the user reads the reply twice, which is exactly the
     * corruption {@see testAStreamThatFailsAfterEmittingToAConsumerIsNotRetried()}
     * exists to prevent on the other channel.
     */
    public function testAStreamThatEmitsThenReportsATransientErrorChunkIsNotRetried(): void
    {
        $provider = new ScriptedProvider([
            ScriptedAttempt::responses([
                new CompleteResponse(content: 'Hel'),
                new CompleteResponse(content: 'lo'),
                new CompleteResponse(
                    content: '',
                    isError: true,
                    errorMessage: 'overloaded',
                    errorTransient: true,
                ),
            ]),
            ScriptedAttempt::chunks(['Hello world']),
        ]);

        $seen = [];
        $sink = static function (string $delta) use (&$seen): void {
            $seen[] = $delta;
        };

        $messages = iterator_to_array(self::runtime($provider)->run(self::app($provider), null, null, $sink));

        $this->assertSame(1, $provider->attempts(), 'no retry once bytes have been emitted, whichever channel failed');
        $this->assertSame(['Hel', 'lo'], $seen, 'and the user saw the partial reply exactly once');
        $this->assertSame(
            'Hello',
            $messages[0]->content(),
            'HelloHello world would mean the transcript carried the reply twice as well',
        );
    }

    /**
     * The same transient failure, before the first delta: retried, and the
     * consumer sees the reply exactly once.
     */
    public function testAStreamThatFailsBeforeItsFirstDeltaIsRetried(): void
    {
        $provider = new ScriptedProvider([
            ScriptedAttempt::throws(self::transient()),
            ScriptedAttempt::chunks(['Hel', 'lo']),
        ]);

        $seen = [];
        $sink = static function (string $delta) use (&$seen): void {
            $seen[] = $delta;
        };

        $messages = iterator_to_array(self::runtime($provider)->run(self::app($provider), null, null, $sink));

        $this->assertSame(2, $provider->attempts());
        $this->assertSame(['Hel', 'lo'], $seen);
        $this->assertSame('Hello', $messages[0]->content(), 'and exactly once in the transcript message too');
    }

    /**
     * A usage-only chunk must NOT count as an emission.
     *
     * Measured on VertexProvider: its SSE decoder reports input tokens on
     * `message_start` as a CompleteResponse of its own, before any text. If that
     * chunk blocked the retry, a Vertex stream that died during generation would
     * never be retryable — the single most common shape this feature exists for.
     */
    public function testAUsageOnlyChunkBeforeAFailureDoesNotBlockTheRetry(): void
    {
        $provider = new ScriptedProvider([
            ScriptedAttempt::responsesThenThrow(
                [new CompleteResponse(content: '', tokensUsed: 40, costUsd: 0.004)],
                self::transient(),
            ),
            ScriptedAttempt::chunks(['ok']),
        ]);

        $seen = [];
        $sink = static function (string $delta) use (&$seen): void {
            $seen[] = $delta;
        };

        $messages = iterator_to_array(self::runtime($provider)->run(self::app($provider), null, null, $sink));

        $this->assertSame(2, $provider->attempts());
        $this->assertSame(['ok'], $seen);
    }

    /**
     * With no sink attached nothing outside `runStreaming()` has observed
     * anything, so a mid-stream failure IS retried in full — and the accumulated
     * buffer must contain the reply ONCE, which is what proves every accumulator
     * was reset rather than only appended to.
     */
    public function testASinklessStreamRetryDoesNotDuplicateTheReply(): void
    {
        $provider = new ScriptedProvider([
            ScriptedAttempt::chunksThenThrow(['Hel', 'lo'], self::transient()),
            ScriptedAttempt::chunks(['Hel', 'lo']),
        ]);

        $messages = iterator_to_array(self::runtime($provider)->run(self::app($provider)));

        $this->assertSame(2, $provider->attempts());
        $this->assertSame(
            'Hello',
            $messages[0]->content(),
            'HelloHello would mean $buffer survived the failed attempt',
        );
    }

    /**
     * Tool calls and reasoning are accumulators too, and a retry that reset only
     * the text buffer would double them.
     */
    public function testARetryResetsToolCallsAndReasoningNotJustTheBuffer(): void
    {
        $provider = new ScriptedProvider([
            ScriptedAttempt::responsesThenThrow(
                [new CompleteResponse(
                    content: 'partial',
                    reasoning: 'thinking',
                    toolCalls: [new ToolCall('call-1', 'counter', [])],
                )],
                self::transient(),
            ),
            ScriptedAttempt::responses([new CompleteResponse(
                content: 'partial',
                reasoning: 'thinking',
                toolCalls: [new ToolCall('call-1', 'counter', [])],
            )]),
        ]);

        $tool = new CountingTool();
        $messages = iterator_to_array(
            self::runtime($provider)->run(self::app($provider, [$tool])),
        );

        $assistant = $messages[0];
        $this->assertInstanceOf(AssistantMessage::class, $assistant);
        $this->assertSame('partial', $assistant->content());
        $this->assertSame('thinking', $assistant->reasoning(), 'thinkingthinking would mean $reasoning leaked');
        $this->assertCount(
            1,
            $assistant->toolCalls() ?? [],
            'two identical tool calls would mean $toolCalls leaked across the attempt',
        );
        $this->assertSame(1, $tool->executions());
    }

    /**
     * The Bundle B2 interaction. `runStreaming()` SUMS usage across chunks
     * because Vertex reports input and output tokens as separate responses, and
     * those figures now drive a spend cap — so a retry that re-enters the loop
     * without clearing the accumulator bills the turn for both attempts.
     *
     * DOMAIN: these are PROVIDER-COUNTED tokens (`CompleteResponse::$tokensUsed`),
     * not the chars/4 estimate {@see \SugarCraft\Crush\Context\ContextWindow}
     * uses elsewhere.
     */
    public function testARetryDoesNotDoubleCountUsage(): void
    {
        $provider = new ScriptedProvider([
            ScriptedAttempt::responsesThenThrow(
                [new CompleteResponse(content: '', tokensUsed: 40, costUsd: 0.004)],
                self::transient(),
            ),
            ScriptedAttempt::responses([
                new CompleteResponse(content: '', tokensUsed: 40, costUsd: 0.004),
                new CompleteResponse(content: 'hi', tokensUsed: 10, costUsd: 0.001),
            ]),
        ]);

        $messages = iterator_to_array(self::runtime($provider)->run(self::app($provider)));
        $usage = $messages[0]->usage();

        $this->assertNotNull($usage);
        $this->assertSame(50, $usage->totalTokens, 'the successful attempt only: 40 + 10, not 90');
        $this->assertEqualsWithDelta(0.005, $usage->costUsd, 1.0e-9);
    }

    public function testAnUnclassifiedStreamErrorChunkLeavesTheOutcomeUnchanged(): void
    {
        // Vertex's SSE `error` event for a non-transient type: not retried, and
        // the accumulated message is byte-identical to the pre-retry behaviour.
        $provider = new ScriptedProvider([
            ScriptedAttempt::responses([
                new CompleteResponse(content: 'half ', tokensUsed: 5),
                new CompleteResponse(
                    content: '',
                    isError: true,
                    errorMessage: 'invalid request',
                    errorTransient: false,
                ),
            ]),
            ScriptedAttempt::chunks(['never reached']),
        ]);

        $messages = iterator_to_array(self::runtime($provider)->run(self::app($provider)));

        $this->assertSame(1, $provider->attempts());
        $this->assertSame('half ', $messages[0]->content());
    }

    // -------------------------------------------------------------------------
    // AgentManager. Same policy, different rollback, and a deliberately
    // different mid-stream gate.
    // -------------------------------------------------------------------------

    private function subAgent(ProviderInterface $provider): array
    {
        $manager = new AgentManager($provider, new SkillRegistry());
        $manager->register(new Agent(
            name: 'worker',
            description: 'test worker',
            prompt: 'You are a test worker.',
            model: 'test-model',
            provider: 'scripted',
            tools: [],
            skillNames: [],
            hooks: [],
            isActive: true,
        ));
        $subAgent = $manager->createSubAgent('worker', 'do the thing');

        return [$manager, $subAgent];
    }

    /**
     * Mid-stream retry IS allowed here, unlike in {@see Runtime::runStreaming()},
     * because `SubAgent::$output` is read by consumers as a whole-value snapshot
     * rather than pushed as deltas — so restoring the field is a correct undo.
     * The assertion that matters is the ONCE: `partialpartial complete` would
     * mean the rollback did not happen.
     */
    public function testASubAgentStreamRetriesMidStreamAndRollsBackItsOutput(): void
    {
        $provider = new ScriptedProvider([
            ScriptedAttempt::chunksThenThrow(['partial'], self::transient()),
            ScriptedAttempt::chunks(['complete answer']),
        ]);
        [$manager, $subAgent] = $this->subAgent($provider);

        iterator_to_array($manager->executeSubAgent($subAgent->id));

        $this->assertSame(2, $provider->attempts());
        $this->assertSame('complete answer', $subAgent->output);
        $this->assertSame(SubAgent::STATUS_COMPLETE, $subAgent->status);
    }

    public function testASubAgentRetryRollsBackUsageToo(): void
    {
        $provider = new ScriptedProvider([
            ScriptedAttempt::responsesThenThrow(
                [new CompleteResponse(content: 'partial', tokensUsed: 40, costUsd: 0.004)],
                self::transient(),
            ),
            ScriptedAttempt::responses([new CompleteResponse(content: 'done', tokensUsed: 10, costUsd: 0.001)]),
        ]);
        [$manager, $subAgent] = $this->subAgent($provider);

        iterator_to_array($manager->executeSubAgent($subAgent->id));

        $this->assertSame('done', $subAgent->output);
        $this->assertSame(10, $subAgent->tokensUsed, '50 would mean the failed attempt was still billed');
        $this->assertEqualsWithDelta(0.001, $subAgent->costUsd, 1.0e-9);
    }

    public function testASubAgentPermanentFailureStillFailsTheSubAgentOnce(): void
    {
        $provider = new ScriptedProvider([
            ScriptedAttempt::throws(self::permanent()),
            ScriptedAttempt::chunks(['never reached']),
        ]);
        [$manager, $subAgent] = $this->subAgent($provider);

        try {
            iterator_to_array($manager->executeSubAgent($subAgent->id));
            $this->fail('a 401 must reach the caller');
        } catch (ClientException) {
            // expected
        }

        $this->assertSame(1, $provider->attempts());
        $this->assertSame(SubAgent::STATUS_FAILED, $subAgent->status);
    }

    public function testASubAgentBatchTransientFailureIsRetried(): void
    {
        $provider = new ScriptedProvider([
            ScriptedAttempt::throws(self::transient()),
            ScriptedAttempt::batch('recovered'),
        ], streams: false);
        [$manager, $subAgent] = $this->subAgent($provider);

        iterator_to_array($manager->executeSubAgent($subAgent->id));

        $this->assertSame(2, $provider->attempts());
        $this->assertSame('recovered', $subAgent->output);
    }
}

/**
 * One scripted provider call: what it yields, and how (or whether) it fails.
 */
final class ScriptedAttempt
{
    /**
     * @param list<CompleteResponse> $responses
     */
    private function __construct(
        public readonly array $responses,
        public readonly ?\Throwable $failure,
        public readonly bool $failAfterResponses,
    ) {}

    /** @param list<string> $chunks */
    public static function chunks(array $chunks, ?array $toolCalls = null): self
    {
        $responses = [];
        foreach ($chunks as $i => $chunk) {
            $responses[] = new CompleteResponse(
                content: $chunk,
                toolCalls: $i === count($chunks) - 1 ? $toolCalls : null,
            );
        }

        return new self($responses, null, false);
    }

    /** @param list<string> $chunks */
    public static function chunksThenThrow(array $chunks, \Throwable $failure): self
    {
        return new self(self::chunks($chunks)->responses, $failure, true);
    }

    /** @param list<CompleteResponse> $responses */
    public static function responses(array $responses): self
    {
        return new self($responses, null, false);
    }

    /** @param list<CompleteResponse> $responses */
    public static function responsesThenThrow(array $responses, \Throwable $failure): self
    {
        return new self($responses, $failure, true);
    }

    public static function throws(\Throwable $failure): self
    {
        return new self([], $failure, false);
    }

    public static function batch(string $content): self
    {
        return new self([new CompleteResponse(content: $content)], null, false);
    }

    public static function response(CompleteResponse $response): self
    {
        return new self([$response], null, false);
    }
}

/**
 * A provider that plays one {@see ScriptedAttempt} per call and counts calls, so
 * a test can assert how many provider requests a turn actually made — the only
 * observable that distinguishes "retried" from "did not retry".
 */
final class ScriptedProvider implements ProviderInterface
{
    private int $attempts = 0;

    /** @param list<ScriptedAttempt> $script */
    public function __construct(
        private readonly array $script,
        private readonly bool $streams = true,
    ) {}

    public function attempts(): int
    {
        return $this->attempts;
    }

    private function next(): ScriptedAttempt
    {
        $attempt = $this->script[$this->attempts] ?? null;
        ++$this->attempts;

        if ($attempt === null) {
            throw new \LogicException(
                'ScriptedProvider ran out of script at call ' . $this->attempts
                . ' - the code under test made more provider calls than the test expected',
            );
        }

        return $attempt;
    }

    public function name(): string
    {
        return 'scripted';
    }

    public function supportsStreaming(): bool
    {
        return $this->streams;
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
        return 100_000;
    }

    public function costPer1kTokens(string $model, string $direction): float
    {
        return 0.0;
    }

    public function complete(CompleteRequest $request): CompleteResponse
    {
        $attempt = $this->next();

        if ($attempt->failure !== null && !$attempt->failAfterResponses) {
            throw $attempt->failure;
        }

        $last = $attempt->responses[count($attempt->responses) - 1] ?? new CompleteResponse(content: '');

        if ($attempt->failure !== null) {
            throw $attempt->failure;
        }

        return $last;
    }

    public function completeStream(CompleteRequest $request): \Generator
    {
        $attempt = $this->next();

        if ($attempt->failure !== null && !$attempt->failAfterResponses) {
            throw $attempt->failure;
        }

        foreach ($attempt->responses as $response) {
            yield $response;
        }

        if ($attempt->failure !== null) {
            throw $attempt->failure;
        }
    }

    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
    {
        return new EmbeddingsResponse(embeddings: []);
    }
}

/**
 * Counts its own executions, which is how
 * {@see ProviderRetryWiringTest::testARetriedTurnDoesNotReRunToolCallsThatAlreadySucceeded()}
 * tells a retry from a replay.
 */
final class CountingTool implements Tool
{
    private int $executions = 0;

    public function executions(): int
    {
        return $this->executions;
    }

    public function name(): string
    {
        return 'counter';
    }

    public function description(): string
    {
        return 'Counts how many times it was executed.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function execute(array $args): ToolResult
    {
        ++$this->executions;

        return new ToolResult('call-1', 'executed ' . $this->executions . ' time(s)');
    }
}

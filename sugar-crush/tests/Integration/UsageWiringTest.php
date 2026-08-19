<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\EchoProvider;
use SugarCraft\Crush\Providers\EmbeddingsRequest;
use SugarCraft\Crush\Providers\EmbeddingsResponse;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Crush\Usage;

/**
 * crush_code.md Phase 5 item 7's first half: the token count and cost every
 * provider already returned, travelling the two seams that used to discard them.
 *
 * The seams, measured before this change: `Runtime::runBatch()` built its
 * {@see \SugarCraft\Crush\Messages\AssistantMessage} from `$response->content`,
 * `->toolCalls` and `->reasoning` and dropped `$response->tokensUsed` /
 * `->costUsd` on the floor; `EngineBackend::complete()` then built a root
 * {@see Message} that had nowhere to put them anyway. So
 * {@see \SugarCraft\Crush\Util\TokenTracker} could not be constructed anywhere
 * in `src/` or `bin/` — there was nothing to feed it.
 *
 * Every test here drives the REAL Runtime and the REAL EngineBackend against a
 * provider double. Nothing calls a private method or asserts on a docblock: the
 * question in each case is what a caller can observe on the returned Message.
 */
final class UsageWiringTest extends TestCase
{
    /**
     * THE test of this bundle, and the one the chain's signature defect would
     * fail. A turn is N provider calls, not one:
     * {@see EngineBackend::complete()} loops while the model keeps asking for
     * tools, and every step is its own billed call. A readout fed from the LAST
     * step would report 7 tokens for a turn that cost 107.
     *
     * Two steps here — step 1 calls `clock` and is billed 100 tokens / $0.10,
     * step 2 answers and is billed 7 tokens / $0.007 — so the turn's figure has
     * to be 107 and $0.107, not 7 and $0.007.
     */
    public function testATurnsUsageIsTheSumOverEveryStepOfTheAgenticLoopNotTheLastOne(): void
    {
        $provider = new ScriptedUsageProvider([
            [
                'response' => new CompleteResponse(
                    content: 'let me check the clock',
                    toolCalls: [new ToolCall('call_1', 'clock', [])],
                    tokensUsed: 100,
                    costUsd: 0.10,
                ),
            ],
            ['response' => new CompleteResponse(content: 'it is noon', tokensUsed: 7, costUsd: 0.007)],
        ]);

        $backend = (new EngineBackend($provider, 'scripted'))
            ->withoutHooks()
            ->withTools([$this->clockTool()]);

        $reply = $backend->complete([Message::user('what time is it?')]);

        $this->assertSame('it is noon', $reply->content, 'fixture: the loop must really have run two steps');
        $this->assertSame(2, $provider->calls(), 'fixture: two provider calls, so there are two bills to add up');
        $this->assertNotNull($reply->usage);
        $this->assertSame(107, $reply->usage->totalTokens, 'the sum over steps, not the final step alone');
        $this->assertEqualsWithDelta(0.107, $reply->usage->costUsd, 0.0000001);
    }

    /**
     * A step that reports nothing must not zero out the steps that did. `sum()`
     * skips nulls rather than treating them as zeros, which matters because a
     * provider can answer the tool-calling step with usage and the final step
     * without (or the other way round).
     */
    public function testAStepThatReportsNothingDoesNotEraseTheStepsThatDid(): void
    {
        $provider = new ScriptedUsageProvider([
            [
                'response' => new CompleteResponse(
                    content: 'checking',
                    toolCalls: [new ToolCall('call_1', 'clock', [])],
                    tokensUsed: 90,
                    costUsd: 0.09,
                ),
            ],
            ['response' => new CompleteResponse(content: 'it is noon')],
        ]);

        $reply = (new EngineBackend($provider, 'scripted'))
            ->withoutHooks()
            ->withTools([$this->clockTool()])
            ->complete([Message::user('what time is it?')]);

        $this->assertNotNull($reply->usage);
        $this->assertSame(90, $reply->usage->totalTokens);
        $this->assertEqualsWithDelta(0.09, $reply->usage->costUsd, 0.0000001);
    }

    /**
     * A turn where NO step reported anything carries no usage at all — null, not
     * a zero-valued Usage. This is the common production case (a streamed turn),
     * and it is the difference between the status bar saying nothing and the
     * status bar claiming `$0.0000` was spent.
     */
    public function testATurnWhoseProviderReportedNothingCarriesNoUsageAtAll(): void
    {
        $reply = (new EngineBackend(new EchoProvider(), 'echo'))
            ->withoutHooks()
            ->complete([Message::user('hello')]);

        $this->assertNull($reply->usage, 'nothing reported must stay nothing reported');
    }

    /**
     * The streaming seam. Usage can arrive split across chunks —
     * {@see \SugarCraft\Crush\Providers\VertexProvider} emits input tokens on
     * `message_start` and output tokens on the terminal `message_delta`, each
     * priced separately — so `Runtime::runStreaming()` has to accumulate them.
     * Reading the last chunk alone would bill the turn for its output and none
     * of its input.
     */
    public function testStreamedUsageIsAccumulatedAcrossChunksAndNotReadOffTheLastOne(): void
    {
        $provider = new ChunkedUsageProvider([
            new CompleteResponse(content: '', tokensUsed: 400, costUsd: 0.004),
            new CompleteResponse(content: 'it is '),
            new CompleteResponse(content: 'noon'),
            new CompleteResponse(content: '', tokensUsed: 12, costUsd: 0.0006),
        ]);

        $runtime = new Runtime($provider, new HookManager(new HookRegistry()));
        $messages = iterator_to_array($runtime->run(App::new($provider, 'chunked')));

        $this->assertSame('it is noon', $messages[0]->content(), 'fixture: the text must still assemble whole');
        $usage = $messages[0]->usage();
        $this->assertNotNull($usage);
        $this->assertSame(412, $usage->totalTokens, 'both usage-bearing chunks, not just the terminal one');
        $this->assertEqualsWithDelta(0.0046, $usage->costUsd, 0.0000001);
    }

    /**
     * A stream of pure content chunks — which is what
     * {@see \SugarCraft\Crush\Providers\OpenAIProvider::completeStream()}
     * produces, its own docblock stating that `tokensUsed` and `costUsd` are
     * always 0 there — reports nothing rather than zero.
     */
    public function testAStreamThatNeverReportsUsageYieldsNullNotZero(): void
    {
        $provider = new ChunkedUsageProvider([
            new CompleteResponse(content: 'it is '),
            new CompleteResponse(content: 'noon'),
        ]);

        $runtime = new Runtime($provider, new HookManager(new HookRegistry()));
        $messages = iterator_to_array($runtime->run(App::new($provider, 'chunked')));

        $this->assertNull($messages[0]->usage());
    }

    /**
     * The batch seam on its own, one layer below the agentic loop: whatever the
     * provider reported has to be on the typed AssistantMessage before
     * EngineBackend can sum anything.
     */
    public function testTheBatchPathPutsTheProvidersFiguresOnTheAssistantMessage(): void
    {
        $provider = new ScriptedUsageProvider([
            ['response' => new CompleteResponse(content: 'hi', tokensUsed: 33, costUsd: 0.0011)],
        ]);

        $runtime = new Runtime($provider, new HookManager(new HookRegistry()));
        $messages = iterator_to_array($runtime->run(App::new($provider, 'scripted')));

        $usage = $messages[0]->usage();
        $this->assertNotNull($usage);
        $this->assertSame(33, $usage->totalTokens);
        $this->assertEqualsWithDelta(0.0011, $usage->costUsd, 0.0000001);
    }

    /**
     * The wire shape sent back to the provider must NOT grow a usage key: it is
     * what the next request carries, and the previous call's bill has no business
     * in the next call's prompt.
     */
    public function testUsageDoesNotLeakIntoTheWireShapeSentBackToTheProvider(): void
    {
        $message = new \SugarCraft\Crush\Messages\AssistantMessage('hi', null, null, Usage::new(50, 0.01));

        $this->assertSame(
            ['role', 'content', 'tool_calls', 'reasoning'],
            array_keys($message->toArray()),
        );
    }

    /**
     * The THIRD seam, and the one easiest to leave out: {@see
     * EngineBackend::completeAsync()} runs the turn in a `pcntl_fork()`ed child,
     * so the usage has to cross a socket. The parent unserializes with
     * `allowed_classes => false`, which means the object cannot make the trip —
     * only {@see Usage::toArray()}'s plain array can. This is exactly where
     * `reasoning` and `imageBytes` each had to be threaded separately.
     *
     * Driven the way {@see \SugarCraft\Crush\Tests\Backend\EngineBackendTest}
     * drives one: a single `run()`/`stop()` pair with a safety timer. Measured on
     * this platform it is a real fork (pcntl present, `stream_socket_pair`
     * succeeding); where either is unavailable `completeAsync()` degrades to its
     * documented in-process fallback, on which the same assertion still holds —
     * so the test cannot silently stop covering the seam, only cover it through
     * the other route, and it skips outright where pcntl is absent altogether.
     */
    public function testUsageCrossesTheForkBoundaryOfCompleteAsync(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('stream_socket_pair')) {
            $this->markTestSkipped('completeAsync() needs pcntl + stream_socket_pair to fork at all');
        }

        $provider = new ScriptedUsageProvider([
            ['response' => new CompleteResponse(content: 'forked reply', tokensUsed: 271, costUsd: 0.0271)],
        ]);

        $reply = $this->awaitPromise(
            (new EngineBackend($provider, 'scripted'))
                ->withoutHooks()
                ->completeAsync([Message::user('hello')])
        );

        $this->assertInstanceOf(Message::class, $reply);
        $this->assertSame('forked reply', $reply->content, 'fixture: the child really produced the turn');
        $this->assertNotNull($reply->usage, 'the usage must survive the socket, not be dropped with the child');
        $this->assertSame(271, $reply->usage->totalTokens);
        $this->assertEqualsWithDelta(0.0271, $reply->usage->costUsd, 0.0000001);
    }

    /**
     * And a turn the child reported nothing for arrives with no usage rather than
     * with a zero — the same distinction on the far side of the socket.
     */
    public function testAForkedTurnWithNoReportedUsageArrivesWithNone(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('stream_socket_pair')) {
            $this->markTestSkipped('completeAsync() needs pcntl + stream_socket_pair to fork at all');
        }

        $reply = $this->awaitPromise(
            (new EngineBackend(new EchoProvider(), 'echo'))
                ->withoutHooks()
                ->completeAsync([Message::user('hello')])
        );

        $this->assertInstanceOf(Message::class, $reply);
        $this->assertNull($reply->usage);
    }

    /**
     * A single `run()`/`stop()` pair, for the reason
     * {@see \SugarCraft\Crush\Tests\Backend\EngineBackendTest::awaitPromise()}
     * documents at length: re-entering `run()` from a freshly re-added timer can
     * miss the socket's readability edge and hang.
     */
    private function awaitPromise(\React\Promise\PromiseInterface $promise): mixed
    {
        $loop = \React\EventLoop\Loop::get();
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
            $safety = $loop->addTimer(10.0, static function () use ($loop): void {
                $loop->stop();
            });
            $loop->run();
            $loop->cancelTimer($safety);
        }

        if (!$settled) {
            $this->fail('Promise did not settle within the test timeout');
        }
        if ($error !== null) {
            throw $error;
        }

        return $value;
    }

    /**
     * `Message`'s clone methods have to carry the usage or it vanishes at
     * whichever seam happens to touch the message next — the exact failure mode
     * that lost `reasoning` before it was threaded through the same set.
     *
     * The SET is derived by reflection, the CARRYING is checked by calling each
     * one. Those are two different jobs and only the second can be done with real
     * arguments, which is why they are not collapsed: a hand-written list is
     * complete only until someone adds a seventh clone method, and a
     * generically-invoked one cannot supply a `ToolCall` where a `ToolCall` is
     * wanted. So the reflection half asserts the list has not grown behind the
     * explicit half's back, naming any method it does not cover.
     *
     * `withUsage()` is excluded because it SETS the usage rather than carrying it
     * — checking that it forwards the old value would assert the opposite of its
     * contract.
     */
    public function testEveryMessageCloneMethodCarriesTheUsageForward(): void
    {
        $base = Message::assistant('hi')->withUsage(Usage::new(21, 0.002));

        $clones = [
            'attachFile' => $base->attachFile('/tmp/x'),
            'attachImage' => $base->attachImage('/tmp/x.png'),
            'withToolCalls' => $base->withToolCalls([new \SugarCraft\Crush\ToolCall('t', [], 'i')]),
            'withToolResults' => $base->withToolResults([new \SugarCraft\Crush\ToolResult('i', 'r')]),
            'withReasoning' => $base->withReasoning('because'),
            'withImage' => $base->withImage('bytes', 'kitty'),
        ];

        $reflected = [];
        foreach ((new \ReflectionClass(Message::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $returns = $method->getReturnType();
            if ($method->isStatic() || !$returns instanceof \ReflectionNamedType) {
                continue;
            }
            if (in_array($returns->getName(), ['self', 'static', Message::class], true)) {
                $reflected[] = $method->getName();
            }
        }
        sort($reflected);
        $expected = [...array_keys($clones), 'withUsage'];
        sort($expected);
        $this->assertSame(
            $expected,
            $reflected,
            'Message grew or lost a self-returning method; add it to $clones above (or to the '
            . "withUsage() exclusion) so the usage it must carry is actually checked",
        );

        foreach ($clones as $method => $clone) {
            $this->assertNotNull($clone->usage, "{$method}() dropped the usage");
            $this->assertSame(21, $clone->usage->totalTokens, "{$method}() changed the usage");
        }
    }

    private function clockTool(): Tool
    {
        return new class implements Tool {
            public function name(): string
            {
                return 'clock';
            }

            public function description(): string
            {
                return 'test tool';
            }

            public function inputSchema(): array
            {
                return [];
            }

            public function execute(array $args): ToolResult
            {
                return new ToolResult(toolCallId: 'call_1', content: 'noon');
            }
        };
    }
}

/**
 * A batch provider that answers a scripted sequence of responses, one per
 * `complete()` call, so an agentic turn's successive steps can be given
 * different usage figures. Records how many calls it took, because "the loop
 * really ran twice" is a fixture claim the sum test depends on.
 */
final class ScriptedUsageProvider implements ProviderInterface
{
    private int $calls = 0;

    /** @param list<array{response:CompleteResponse}> $script */
    public function __construct(private readonly array $script) {}

    public function calls(): int
    {
        return $this->calls;
    }

    public function name(): string
    {
        return 'scripted-usage';
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
        return 100000;
    }

    public function costPer1kTokens(string $model, string $direction): float
    {
        return 0.0;
    }

    public function complete(CompleteRequest $request): CompleteResponse
    {
        $step = $this->script[$this->calls] ?? null;
        ++$this->calls;

        return $step['response'] ?? new CompleteResponse(content: 'script exhausted');
    }

    public function completeStream(CompleteRequest $request): \Generator
    {
        yield $this->complete($request);
    }

    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
    {
        return new EmbeddingsResponse(embeddings: []);
    }
}

/**
 * A streaming provider that yields a fixed list of chunks, so usage can be
 * placed on whichever chunks a test wants — including on a content-free chunk,
 * which is exactly how the real Anthropic-shaped SSE streams report it.
 */
final class ChunkedUsageProvider implements ProviderInterface
{
    /** @param list<CompleteResponse> $chunks */
    public function __construct(private readonly array $chunks) {}

    public function name(): string
    {
        return 'chunked-usage';
    }

    public function supportsStreaming(): bool
    {
        return true;
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
        return 100000;
    }

    public function costPer1kTokens(string $model, string $direction): float
    {
        return 0.0;
    }

    public function complete(CompleteRequest $request): CompleteResponse
    {
        return new CompleteResponse(content: 'batch');
    }

    public function completeStream(CompleteRequest $request): \Generator
    {
        foreach ($this->chunks as $chunk) {
            yield $chunk;
        }
    }

    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
    {
        return new EmbeddingsResponse(embeddings: []);
    }
}

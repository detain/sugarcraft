<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\App\App;
use SugarCraft\Code\Events\ToolFinished;
use SugarCraft\Code\Events\ToolStarted;
use SugarCraft\Code\Hooks\HookContext;
use SugarCraft\Code\Hooks\HookEvent;
use SugarCraft\Code\Hooks\HookInterface;
use SugarCraft\Code\Hooks\HookManager;
use SugarCraft\Code\Hooks\HookRegistry;
use SugarCraft\Code\Hooks\HookResult;
use SugarCraft\Code\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Code\Messages\AssistantMessage;
use SugarCraft\Code\Messages\ToolResultMessage;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\CompleteResponse;
use SugarCraft\Code\Providers\CustomProvider;
use SugarCraft\Code\Providers\EmbeddingsRequest;
use SugarCraft\Code\Providers\EmbeddingsResponse;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Runtime;
use SugarCraft\Code\Tools\ParallelSafe;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolCall;
use SugarCraft\Code\Tools\ToolResult;

/**
 * Audit 15a A11: a tool call whose arguments the provider could not decode
 * reached {@see Runtime} as an ordinary call with `[]`, so the tool RAN with
 * no arguments and the model read that tool's "missing parameter" error - a
 * problem that was never the parameters'. Runtime now answers such a call
 * ({@see ToolCall::argumentsError()} set) with an error result naming the
 * JSON problem, on both the sequential and the concurrent arm, and never
 * reaches the hook chain or the tool.
 *
 * C-TOOL-7.e (CC-new#12): a reply whose EVERY tool call carries such an
 * error, and which says no user-visible text at all, is now discarded and
 * the same request re-issued — bounded by
 * {@see Runtime::MAX_MALFORMED_RESAMPLES} (a count, not a timeout, E646) —
 * before the A11 error-result path above runs unchanged. The tests below
 * the three original ones drive that resample through the real loops.
 */
final class RuntimeMalformedToolArgumentsTest extends TestCase
{
    private const TRUNCATED = '{"path": "a';

    /** The carrier text a provider sets when its JSON decode failed. */
    private const A11_ERROR = 'arguments were not valid JSON (Syntax error): ' . self::TRUNCATED;

    private string $markerDir;

    protected function setUp(): void
    {
        $this->markerDir = sys_get_temp_dir() . '/sc_a11_' . getmypid() . '_' . bin2hex(random_bytes(6));
        mkdir($this->markerDir, 0o700, true);

        // An unarmed sink DROPS rows, and the C-TOOL-7.e resample notice
        // travels through it (house pattern from
        // Providers\RuntimeRetryAfterProductionPathTest).
        RuntimeNoticeSink::reset();
        RuntimeNoticeSink::arm(false);
    }

    protected function tearDown(): void
    {
        RuntimeNoticeSink::reset();
        foreach (scandir($this->markerDir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @unlink($this->markerDir . '/' . $entry);
            }
        }
        @rmdir($this->markerDir);

        parent::tearDown();
    }

    /**
     * The audit's scenario end to end: a real CustomProvider stream carrying
     * `{"path": "a`, through Runtime::run().
     *
     * C-TOOL-7.e changed the SHAPE of this arrival, not its verdict: a reply
     * that streamed no text and carries an argument error on every call is
     * now discarded and the same request re-issued up to
     * {@see Runtime::MAX_MALFORMED_RESAMPLES} times before standing as the
     * A11 error result below. The stub answers every re-issue with the same
     * broken body (a fresh Response per call - one Response stream cannot be
     * read twice), so the third reply exhausts the bound and the original
     * A11 outcome, and its two resample notices, are all pinned here.
     */
    public function testATruncatedStreamedPayloadBecomesAnErrorResultAndTheToolNeverRuns(): void
    {
        $body = 'data: ' . json_encode(['choices' => [['index' => 0, 'delta' => ['tool_calls' => [[
            'index' => 0,
            'id' => 'call_trunc',
            'type' => 'function',
            'function' => ['name' => 'spy_read', 'arguments' => self::TRUNCATED],
        ]]], 'finish_reason' => null]]]) . "\n\n"
            . 'data: ' . json_encode(['choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'tool_calls']]]) . "\n\n"
            . "data: [DONE]\n\n";

        $client = $this->createMock(Client::class);
        $client->method('post')->willReturnCallback(static fn (): Response => new Response(200, [], $body));
        $custom = new CustomProvider('custom', 'https://api.example.com', 'm', null, $client, true, true);

        $calls = 0;
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('a11-stub');
        $provider->method('supportsStreaming')->willReturn(true);
        $provider->method('completeStream')->willReturnCallback(
            static function (CompleteRequest $request) use ($custom, &$calls): \Generator {
                $calls++;

                yield from $custom->completeStream($request);
            },
        );

        $spy = $this->spyTool('spy_read', parallel: false);
        [$results, $events] = $this->runTurn($provider, [$spy]);

        $this->assertSame(1 + Runtime::MAX_MALFORMED_RESAMPLES, $calls, 'the bound, then the A11 result stands');
        $this->assertSame([
            sprintf(Runtime::MALFORMED_RESAMPLE_NOTICE_FORMAT, 1, Runtime::MAX_MALFORMED_RESAMPLES),
            sprintf(Runtime::MALFORMED_RESAMPLE_NOTICE_FORMAT, 2, Runtime::MAX_MALFORMED_RESAMPLES),
        ], RuntimeNoticeSink::drain());

        $toolResults = array_values(array_filter($results, static fn (mixed $m): bool => $m instanceof ToolResultMessage));
        $this->assertCount(1, $toolResults);
        $this->assertSame('call_trunc', $toolResults[0]->toolCallId());
        $this->assertTrue($toolResults[0]->isError());
        $this->assertStringContainsString('not valid JSON', $toolResults[0]->content());
        $this->assertStringContainsString(self::TRUNCATED, $toolResults[0]->content());
        $this->assertSame([], $this->executions(), 'the tool must not run with the [] placeholder');

        $this->assertCount(2, $events, 'one ToolStarted and one ToolFinished, like "Tool not found"');
        $this->assertInstanceOf(ToolStarted::class, $events[0]);
        $this->assertInstanceOf(ToolFinished::class, $events[1]);
        $this->assertTrue($events[1]->result->isError());
        $this->assertSame($toolResults[0]->content(), $events[1]->result->content());
    }

    public function testTheSequentialArmSkipsThePreToolUseChainForAMalformedCall(): void
    {
        $registry = new HookRegistry();
        $seen = new \ArrayObject();
        $registry->register(new class($seen) implements HookInterface {
            public function __construct(private \ArrayObject $seen) {}
            public function name(): string { return 'a11-recorder'; }
            public function event(): HookEvent { return HookEvent::PreToolUse; }
            public function matcher(): string { return '.*'; }
            public function execute(HookContext $context): HookResult
            {
                $this->seen->append($context->toolName);

                return HookResult::allow();
            }
        });

        $call = (new ToolCall('call_bad', 'spy_read', []))->withArgumentsError('arguments were not valid JSON (Syntax error): ' . self::TRUNCATED);
        $messages = $this->dispatch([$call], [$this->spyTool('spy_read', parallel: false)], $registry);

        $this->assertCount(1, $messages);
        $this->assertTrue($messages[0]->isError());
        $this->assertStringContainsString('The tool was not run because its arguments were not valid JSON', $messages[0]->content());
        $this->assertSame([], $this->executions());
        $this->assertCount(0, $seen, 'no argument map exists for a hook to judge');
    }

    /**
     * The concurrent arm gates every member up front; a malformed member must
     * settle there as an error while its well-formed sibling still runs, and
     * results keep provider order. Executions are counted through marker
     * files because a forked child cannot bump an in-memory counter.
     */
    public function testTheConcurrentArmRefusesTheMalformedMemberAndRunsItsSibling(): void
    {
        $tool = $this->spyTool('spy_par', parallel: true);
        $calls = [
            (new ToolCall('call_par_bad', 'spy_par', []))->withArgumentsError('arguments were not valid JSON (Syntax error): ' . self::TRUNCATED),
            new ToolCall('call_par_good', 'spy_par', ['path' => 'b.php']),
        ];

        $finished = [];
        $messages = $this->dispatch($calls, [$tool], null, static function (object $event) use (&$finished): void {
            if ($event instanceof ToolFinished) {
                $finished[$event->toolCallId] = $event;
            }
        });

        $this->assertSame(
            ['call_par_bad', 'call_par_good'],
            array_map(static fn (ToolResultMessage $m): string => $m->toolCallId(), $messages),
        );
        $this->assertTrue($messages[0]->isError());
        $this->assertStringContainsString('not valid JSON', $messages[0]->content());
        $this->assertFalse($messages[1]->isError());
        $this->assertSame('ran with b.php', $messages[1]->content());
        $this->assertSame(['b.php'], $this->executions(), 'only the well-formed sibling ran');
        $this->assertCount(2, $finished);
        $this->assertTrue($finished['call_par_bad']->result->isError());
    }

    /**
     * C-TOOL-7.e, the streaming half: a reply that carries nothing but an
     * undecodable call is discarded and the SAME request re-issued; the
     * second reply's good call runs, and the discarded call never reaches
     * the A11 error-result path (it cost a notice, not a step).
     */
    public function testAnAllMalformedReplyIsResampledAndTheGoodReplyRuns(): void
    {
        $provider = $this->stubProvider([
            new CompleteResponse('', null, [(new ToolCall('call_bad', 'spy_read', []))->withArgumentsError(self::A11_ERROR)]),
            new CompleteResponse('', null, [new ToolCall('call_good', 'spy_read', ['path' => 'a.php'])]),
        ], streaming: true);

        [$results] = $this->runTurn($provider, [$this->spyTool('spy_read', parallel: false)]);

        self::assertSame(2, $provider->calls, 'the all-malformed reply must re-issue the same request once');
        self::assertSame(['a.php'], $this->executions(), 'the second reply runs once');

        $toolResults = array_values(array_filter($results, static fn (mixed $m): bool => $m instanceof ToolResultMessage));
        self::assertCount(1, $toolResults, 'the discarded reply never becomes an error result');
        self::assertSame('call_good', $toolResults[0]->toolCallId());
        self::assertFalse($toolResults[0]->isError());

        self::assertSame(
            [sprintf(Runtime::MALFORMED_RESAMPLE_NOTICE_FORMAT, 1, Runtime::MAX_MALFORMED_RESAMPLES)],
            RuntimeNoticeSink::drain(),
            'exactly one notice line rides the announce channel the resample used',
        );
    }

    /**
     * C-TOOL-7.e, the bound (batch half — the shape the brief pins the
     * standing A11 result on): after MAX_MALFORMED_RESAMPLES discards,
     * today's error-result path runs unchanged — the call is answered with
     * the A11 failure, the tool never runs, and the provider was asked
     * exactly 1 + bound times.
     */
    public function testAfterTwoResamplesTheA11ErrorResultStands(): void
    {
        $malformed = new CompleteResponse('', null, [(new ToolCall('call_bad', 'spy_read', []))->withArgumentsError(self::A11_ERROR)]);
        $provider = $this->stubProvider([$malformed, $malformed, $malformed], streaming: false);

        [$results] = $this->runTurn($provider, [$this->spyTool('spy_read', parallel: false)]);

        self::assertSame(3, $provider->calls, 'one reply plus the two allowed resamples, no more');
        self::assertSame(Runtime::MAX_MALFORMED_RESAMPLES + 1, $provider->calls);

        $toolResults = array_values(array_filter($results, static fn (mixed $m): bool => $m instanceof ToolResultMessage));
        self::assertCount(1, $toolResults);
        self::assertTrue($toolResults[0]->isError());
        self::assertStringContainsString('not valid JSON', $toolResults[0]->content());
        self::assertSame([], $this->executions());

        self::assertSame([
            sprintf(Runtime::MALFORMED_RESAMPLE_NOTICE_FORMAT, 1, Runtime::MAX_MALFORMED_RESAMPLES),
            sprintf(Runtime::MALFORMED_RESAMPLE_NOTICE_FORMAT, 2, Runtime::MAX_MALFORMED_RESAMPLES),
        ], RuntimeNoticeSink::drain(), 'one notice line per discarded reply, and the bound itself adds none');
    }

    /**
     * C-TOOL-7.e, the door: a reply that streamed user-visible text is NOT
     * resampled even when every call on it is undecodable — that text is
     * already on the screen and append-only; today's A11 result stands
     * (the audit's own scenario, unchanged).
     */
    public function testAReplyWithStreamedTextIsNotResampled(): void
    {
        $provider = $this->stubProvider([
            new CompleteResponse('Sure, reading it now. ', null, [(new ToolCall('call_bad', 'spy_read', []))->withArgumentsError(self::A11_ERROR)]),
        ], streaming: true);

        [$results] = $this->runTurn($provider, [$this->spyTool('spy_read', parallel: false)]);

        self::assertSame(1, $provider->calls, 'a reply that spoke to the user is never thrown away');

        $assistants = array_values(array_filter($results, static fn (mixed $m): bool => $m instanceof AssistantMessage));
        self::assertCount(1, $assistants);
        self::assertStringContainsString('Sure, reading it now. ', $assistants[0]->content());

        $toolResults = array_values(array_filter($results, static fn (mixed $m): bool => $m instanceof ToolResultMessage));
        self::assertCount(1, $toolResults);
        self::assertTrue($toolResults[0]->isError());
        self::assertSame([], RuntimeNoticeSink::drain());
    }

    /**
     * C-TOOL-7.e, billing honesty: the discarded replies still cost their
     * provider calls, so their usage is summed into the step's final
     * message rather than dropped with the reply.
     */
    public function testUsageFromDiscardedRepliesIsSummedIntoTheStep(): void
    {
        $malformed = (new ToolCall('call_bad', 'spy_read', []))->withArgumentsError(self::A11_ERROR);
        $provider = $this->stubProvider([
            new CompleteResponse('', null, [$malformed], tokensUsed: 10, costUsd: 0.01),
            new CompleteResponse('', null, [$malformed], tokensUsed: 10, costUsd: 0.01),
            new CompleteResponse('', null, [new ToolCall('call_good', 'spy_read', ['path' => 'a.php'])], tokensUsed: 5, costUsd: 0.02),
        ], streaming: false);

        [$results] = $this->runTurn($provider, [$this->spyTool('spy_read', parallel: false)]);

        $assistants = array_values(array_filter($results, static fn (mixed $m): bool => $m instanceof AssistantMessage));
        self::assertCount(1, $assistants);
        self::assertNotNull($assistants[0]->usage());
        self::assertSame(25, $assistants[0]->usage()->totalTokens, 'discarded 10 + 10 plus the final 5');
        self::assertEqualsWithDelta(0.04, $assistants[0]->usage()->costUsd, 1e-9);
    }

    /**
     * Hand the runtime a fixed script of replies, one per provider call,
     * counting the calls. The extra calls past the script repeat its last
     * row — a test that bounds the resample asserts the counter, so a
     * runaway loop must fail ON TIME, loudly, never hang the suite.
     *
     * @param list<CompleteResponse> $replies
     */
    private function stubProvider(array $replies, bool $streaming): ProviderInterface
    {
        return new class ($replies, $streaming) implements ProviderInterface {
            public int $calls = 0;

            /** @param list<CompleteResponse> $replies */
            public function __construct(private array $replies, private bool $streaming)
            {
            }

            public function name(): string
            {
                return 'a11-resample-stub';
            }

            public function supportsStreaming(): bool
            {
                return $this->streaming;
            }

            public function supportsFunctionCalling(): bool
            {
                return true;
            }

            public function supportsVision(): bool
            {
                return false;
            }

            public function supportsDocuments(): bool
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
                return $this->replies[min($this->calls++, count($this->replies) - 1)];
            }

            public function completeStream(CompleteRequest $request): \Generator
            {
                yield $this->replies[min($this->calls++, count($this->replies) - 1)];
            }

            public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
            {
                return new EmbeddingsResponse([]);
            }
        };
    }

    /**
     * @param list<Tool> $tools
     * @return array{0: list<mixed>, 1: list<object>}
     */
    private function runTurn(ProviderInterface $provider, array $tools): array
    {
        $runtime = new Runtime($provider, new HookManager(new HookRegistry()));
        $app = App::new($provider, 'm')->withTools($tools);

        $events = [];
        $results = iterator_to_array($runtime->run($app, static function (object $event) use (&$events): void {
            $events[] = $event;
        }), false);

        return [$results, $events];
    }

    /**
     * @param list<ToolCall> $calls
     * @param list<Tool> $tools
     * @return list<ToolResultMessage>
     */
    private function dispatch(array $calls, array $tools, ?HookRegistry $registry = null, ?\Closure $onEvent = null): array
    {
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('a11-stub');
        $runtime = new Runtime($provider, new HookManager($registry ?? new HookRegistry()));
        $app = App::new($provider, 'm')->withTools($tools);

        $method = new \ReflectionMethod(Runtime::class, 'executeToolCalls');

        return array_values(iterator_to_array($method->invoke($runtime, $calls, $app, $onEvent), false));
    }

    /** @return list<string> the `path` argument of every execution, sorted */
    private function executions(): array
    {
        $paths = [];
        foreach (glob($this->markerDir . '/run-*') ?: [] as $file) {
            $paths[] = (string) file_get_contents($file);
        }
        sort($paths);

        return $paths;
    }

    private function spyTool(string $name, bool $parallel): Tool
    {
        $spy = new class($name, $this->markerDir) implements Tool {
            public function __construct(private string $toolName, private string $markerDir) {}

            public function name(): string
            {
                return $this->toolName;
            }

            public function description(): string
            {
                return 'records every execution';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object'];
            }

            public function execute(array $args): ToolResult
            {
                $path = (string) ($args['path'] ?? '(none)');
                file_put_contents($this->markerDir . '/run-' . getmypid() . '-' . bin2hex(random_bytes(4)), $path);

                return new ToolResult(toolCallId: $this->toolName, content: "ran with {$path}");
            }
        };

        if (!$parallel) {
            return $spy;
        }

        return new class($spy) implements Tool, ParallelSafe {
            public function __construct(private Tool $inner) {}

            public function name(): string
            {
                return $this->inner->name();
            }

            public function description(): string
            {
                return $this->inner->description();
            }

            public function inputSchema(): array
            {
                return $this->inner->inputSchema();
            }

            public function isParallelSafe(): bool
            {
                return true;
            }

            public function execute(array $args): ToolResult
            {
                return $this->inner->execute($args);
            }
        };
    }
}

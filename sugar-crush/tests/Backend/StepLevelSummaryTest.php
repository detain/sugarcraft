<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Context\Compaction\StepSummarizer;
use SugarCraft\Crush\Context\Pruning\CompressionBlock;
use SugarCraft\Crush\Context\TurnContextBlock;
use SugarCraft\Crush\Events\StepStarted;
use SugarCraft\Crush\Events\UsageUpdated;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\Message;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Crush\Usage;

/**
 * Roadmap 2.4-1: a step still over its budget after the prune is summarised
 * by the turn's own model over the request it was last sent — same system
 * prompt, same tools, plus a "do not call tools" row — and the next request
 * carries the summary in place of the rows it covers, with the unsent tail
 * verbatim.
 */
final class StepLevelSummaryTest extends TestCase
{
    private ?string $root = null;

    protected function tearDown(): void
    {
        if ($this->root !== null) {
            @rmdir($this->root);
        }
        putenv('SUGARCRUSH_SUMMARY_MODEL');
    }

    public function testTheStepAfterTheSummaryIsSmallerAndHoldsTheSummary(): void
    {
        $probe = self::probe();
        $provider = self::provider();
        $events = [];

        $turn = $this->engine($provider)->withTools([$probe])->completeTranscript(
            [new UserMessage('map the project')],
            onStep: static function (object $event) use (&$events): void {
                $events[] = $event;
            },
        );

        // Three tool steps (the third request ~60k), the summary request, the
        // fourth step, which answers.
        $this->assertCount(5, $provider->requests);
        $this->assertSame(3, $probe->runs, 'the summary request ran no tool');

        $summary = $provider->requests[3];
        $last = $summary->messages[array_key_last($summary->messages)];
        $this->assertInstanceOf(UserMessage::class, $last);
        $this->assertSame(StepSummarizer::INSTRUCTION, $last->content());
        $this->assertSame($provider->requests[2]->tools, $summary->tools, 'the same tools stay advertised');
        $this->assertSame($provider->requests[2]->systemPrompt, $summary->systemPrompt, 'the same system prompt');
        $this->assertSame('m', $summary->model, 'the turn\'s own model by default');
        $this->assertSame(
            self::wire(\array_slice($provider->requests[2]->messages, 0, count($summary->messages) - 1)),
            self::wire(\array_slice($summary->messages, 0, -1)),
            'the summary request is the last request sent, plus one row — a cached prefix',
        );

        $after = $provider->requests[4];
        $this->assertStringStartsWith(sprintf(CompressionBlock::HEADER, 1), $after->messages[0]->content());
        $this->assertStringContainsString('SUMMARY OF STEPS 1-2', $after->messages[0]->content());
        $this->assertInstanceOf(AssistantMessage::class, $after->messages[1], 'the tail starts at a step opener');
        $this->assertSame('c3', $after->messages[1]->toolCalls()[0]->id(), 'the unsent step is kept verbatim');
        $this->assertSame(['c3'], self::resultIds($after->messages), 'c1 and c2 are in the summary, c3 is sent whole');
        $this->assertLessThan(self::wireBytes($provider->requests[2]), self::wireBytes($after), 'the request after the summary is smaller');

        $steps = array_values(array_filter($events, static fn (object $e): bool => $e instanceof StepStarted));
        $this->assertSame([1, 2, 3, 4], array_map(static fn (StepStarted $e): int => $e->step, $steps));
        $this->assertFalse($steps[3]->pressure?->isOverBudget(), 'step 4 reports the request it sent');
        $usage = array_values(array_filter($events, static fn (object $e): bool => $e instanceof UsageUpdated));
        $this->assertCount(5, $usage, 'the summary call is billed like a step');

        $this->assertSame(['c1', 'c2', 'c3'], self::resultIds($turn->transcript), 'the turn\'s own rows are never rewritten');
        $this->assertSame('all mapped', $turn->reply->content);
    }

    public function testTheSummaryModelOverrideIsHonoured(): void
    {
        $provider = self::provider();

        $this->engine($provider)->withTools([self::probe()])->withSummaryModel('small-summariser')
            ->completeTranscript([new UserMessage('map the project')]);

        $this->assertSame('small-summariser', $provider->requests[3]->model);
        $this->assertSame('m', $provider->requests[4]->model, 'only the summary moves');
    }

    /**
     * Roadmap 2.4-2 (carried from W5): the override is resolved ONCE, at
     * launch, onto the backend — a turn never re-reads the environment, so a
     * variable set after launch moves nothing.
     */
    public function testTheTurnDoesNotReReadTheEnvironment(): void
    {
        putenv('SUGARCRUSH_SUMMARY_MODEL=late-summariser');
        $provider = self::provider();

        $this->engine($provider)->withTools([self::probe()])->completeTranscript([new UserMessage('map the project')]);

        $this->assertSame('m', $provider->requests[3]->model, 'the launch-time field, not the live variable');
    }

    public function testAFailedSummaryLeavesTheStepToGoOutAsItStands(): void
    {
        $calls = 0;
        $provider = new ScriptedProvider([
            static function (CompleteRequest $request) use (&$calls): CompleteResponse {
                $calls++;
                if (self::asksForSummary($request)) {
                    throw new \RuntimeException('summary call failed');
                }

                return $calls <= 3
                    ? new CompleteResponse(content: '', toolCalls: [new ToolCall("c{$calls}", 'probe', ['n' => $calls])])
                    : new CompleteResponse(content: 'finished anyway');
            },
        ], contextWindow: 100_000);

        $turn = $this->engine($provider)->withTools([self::probe()])->completeTranscript([new UserMessage('map the project')]);

        $this->assertCount(5, $provider->requests);
        $this->assertSame(['c1', 'c2', 'c3'], self::resultIds($provider->requests[4]->messages), 'nothing was replaced');
        $this->assertSame('finished anyway', $turn->reply->content);
    }

    public function testCutIndexSnapsToAStepOpenerAtOrBeforeTheUnsentTail(): void
    {
        $rows = [
            new UserMessage('go'),
            new AssistantMessage('', [new ToolCall('a', 'probe', [])]),
            new ToolResultMessage('a', 'x'),
            new UserMessage(TurnContextBlock::FENCE . "\nstate\n</turn-context>"),
            new AssistantMessage('', [new ToolCall('b', 'probe', []), new ToolCall('c', 'probe', [])]),
            new ToolResultMessage('b', 'x'),
            new ToolResultMessage('c', 'x'),
            new AssistantMessage('', [new ToolCall('d', 'probe', [])]),
            new ToolResultMessage('d', 'x'),
        ];

        $this->assertSame(7, StepSummarizer::cutIndex($rows, 7), 'the unsent step opens at 7');
        $this->assertSame(4, StepSummarizer::cutIndex($rows, 6), 'never between a call and its result');
        $this->assertSame(7, StepSummarizer::cutIndex($rows, 99), 'past the end: the last opener');
        $this->assertSame(1, StepSummarizer::cutIndex($rows, 1), 'the first opener, with the prompt before it');
        $this->assertNull(StepSummarizer::cutIndex($rows, 0), 'nothing before row 0 to summarise');
        $this->assertNull(StepSummarizer::cutIndex([new UserMessage('go'), new AssistantMessage('hi')], 2));
    }

    /**
     * Steps 1-3 each call `probe` (30k of output); the summary request is
     * answered with a short summary; step 4 answers. 100k window: 80k budget,
     * so step 4's request (~90k) is the first over it.
     */
    private static function provider(): ScriptedProvider
    {
        $step = 0;

        return new ScriptedProvider([
            static function (CompleteRequest $request) use (&$step): CompleteResponse {
                if (self::asksForSummary($request)) {
                    return new CompleteResponse(content: 'SUMMARY OF STEPS 1-2: probed twice.', usage: Usage::new(60, 0.0, 50, 10, 0));
                }
                $step++;

                return $step <= 3
                    ? new CompleteResponse(content: '', toolCalls: [new ToolCall("c{$step}", 'probe', ['n' => $step])])
                    : new CompleteResponse(content: 'all mapped');
            },
        ], contextWindow: 100_000);
    }

    private static function asksForSummary(CompleteRequest $request): bool
    {
        $last = $request->messages[array_key_last($request->messages)] ?? null;

        return $last instanceof UserMessage && $last->content() === StepSummarizer::INSTRUCTION;
    }

    private function engine(ScriptedProvider $provider): EngineBackend
    {
        $this->root ??= sys_get_temp_dir() . '/crush-stepsum-' . bin2hex(random_bytes(6));
        if (!is_dir($this->root)) {
            mkdir($this->root, 0o700, true);
        }

        return EngineBackend::new($provider, 'm')->withoutHooks()->withRoot($this->root);
    }

    /** @return object{runs:int}&Tool */
    private static function probe(): Tool
    {
        return new class () implements Tool {
            public int $runs = 0;

            public function name(): string
            {
                return 'probe';
            }

            public function description(): string
            {
                return 'Reads a lot.';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => ['n' => ['type' => 'integer']]];
            }

            public function execute(array $args): ToolResult
            {
                $this->runs++;

                return new ToolResult(toolCallId: '', content: str_repeat('lorem ipsum ', 10_000));
            }
        };
    }

    /** @param list<mixed> $messages */
    private static function wire(array $messages): string
    {
        return (string) json_encode(array_map(static fn (Message $m): array => $m->toArray(), $messages));
    }

    /**
     * @param list<mixed> $messages
     * @return list<string>
     */
    private static function resultIds(array $messages): array
    {
        $ids = [];
        foreach ($messages as $message) {
            if ($message instanceof ToolResultMessage) {
                $ids[] = $message->toolCallId();
            }
        }

        return $ids;
    }

    private static function wireBytes(CompleteRequest $request): int
    {
        return strlen(self::wire($request->messages));
    }
}

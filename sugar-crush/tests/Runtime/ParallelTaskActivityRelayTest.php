<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Events\SubAgentActivity;
use SugarCraft\Crush\Events\ToolFinished;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Support\ForkedChild;
use SugarCraft\Crush\Tools\ActivitySink;
use SugarCraft\Crush\Tools\ParallelSafe;
use SugarCraft\Crush\Tools\StreamsActivity;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * The per-member datagram relay in {@see Runtime::executeConcurrently()}
 * (step RELAY): beats cross while the member still runs, and a member whose
 * process ends without closing its run still gets its row closed — before
 * its ToolFinished, in the order the real finished beat would have kept.
 */
final class ParallelTaskActivityRelayTest extends TestCase
{
    protected function setUp(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('Concurrent tool dispatch requires ext-pcntl.');
        }
    }

    public function testAMemberThatDiesMidRunHasItsRunClosedBeforeItsToolFinished(): void
    {
        $timeline = [];
        /** @var list<SubAgentActivity> $beats */
        $beats = [];
        $emitter = static function (SubAgentActivity $beat) use (&$timeline, &$beats): void {
            $beats[] = $beat;
            $timeline[] = $beat->op . ':' . $beat->name;
        };
        $onEvent = static function (object $event) use (&$timeline): void {
            if ($event instanceof ToolFinished) {
                $timeline[] = 'tool-finished:' . $event->toolName;
            }
        };

        $results = $this->run2(
            self::delegator('dies', $emitter, dieAfterProgress: true),
            self::delegator('lives', $emitter),
            $onEvent,
        );

        $this->assertTrue($results[0]->isError(), 'a member that died reports its call as failed');
        $this->assertFalse($results[1]->isError(), $results[1]->content());

        $died = array_values(array_filter($beats, static fn (SubAgentActivity $b): bool => $b->name === 'dies'));
        $this->assertSame(
            [SubAgentActivity::OP_STARTED, SubAgentActivity::OP_PROGRESS, SubAgentActivity::OP_FINISHED],
            array_map(static fn (SubAgentActivity $b): string => $b->op, $died),
            'the parent closes the run its process never closed',
        );
        $this->assertSame(3, $died[2]->seq, 'the synthesised close continues the run\'s own seq');
        $this->assertSame('-> Read', $died[2]->tail, 'and keeps the last thing the run was seen doing');
        $this->assertSame(77, $died[2]->tokensUsed, 'and its running totals');

        $this->assertLessThan(
            array_search('tool-finished:dies', $timeline, true),
            array_search('finished:dies', $timeline, true),
            'the row settles before the call\'s ToolFinished',
        );

        $lived = array_values(array_filter($beats, static fn (SubAgentActivity $b): bool => $b->name === 'lives'));
        $this->assertCount(3, $lived, 'a member that closed its own run is not closed a second time');
    }

    public function testBeatsCrossWhileTheMemberIsStillRunning(): void
    {
        $startedAt = null;
        $finishedToolAt = null;
        $emitter = static function (SubAgentActivity $beat) use (&$startedAt): void {
            if ($beat->op === SubAgentActivity::OP_STARTED && $beat->name === 'slow') {
                $startedAt ??= microtime(true);
            }
        };
        $onEvent = static function (object $event) use (&$finishedToolAt): void {
            if ($event instanceof ToolFinished && $event->toolName === 'slow') {
                $finishedToolAt = microtime(true);
            }
        };

        $this->run2(
            self::delegator('slow', $emitter, holdMicros: 600_000),
            self::delegator('quick', $emitter),
            $onEvent,
        );

        $this->assertNotNull($startedAt, 'the started beat crossed');
        $this->assertNotNull($finishedToolAt);
        $this->assertGreaterThan(0.3, $finishedToolAt - $startedAt, 'the started beat was replayed live, not batched at the member\'s exit');
    }

    /**
     * @return list<ToolResultMessage>
     */
    private function run2(Tool $a, Tool $b, \Closure $onEvent): array
    {
        $provider = $this->createMock(ProviderInterface::class);
        $runtime = new Runtime($provider, new HookManager(new HookRegistry()), null, true);
        $app = App::new($provider, 'gpt-4')->withTools([$a, $b]);

        $method = new \ReflectionMethod($runtime, 'executeToolCalls');

        /** @var list<ToolResultMessage> */
        return array_values(iterator_to_array($method->invoke(
            $runtime,
            [new ToolCall('call_a', $a->name(), []), new ToolCall('call_b', $b->name(), [])],
            $app,
            $onEvent,
            null,
            null,
        )));
    }

    /**
     * A parallel-safe stand-in for TaskTool that reports one run through
     * whatever it is bound with — the emitter in the parent, the relay's sink
     * once forked.
     */
    private static function delegator(string $name, \Closure $emitter, bool $dieAfterProgress = false, int $holdMicros = 0): Tool
    {
        $parent = getmypid();

        return new class ($name, $emitter, $dieAfterProgress, $holdMicros, $parent) implements Tool, ParallelSafe, StreamsActivity {
            public function __construct(
                private string $name,
                private \Closure $emitter,
                private bool $dieAfterProgress,
                private int $holdMicros,
                private int|false $parent,
            ) {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function description(): string
            {
                return 'reports a delegated run';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): ToolResult
            {
                $id = $this->name . '-run';
                ($this->emitter)(new SubAgentActivity(SubAgentActivity::OP_STARTED, $id, $this->name, 'task', 1, ''));
                usleep($this->holdMicros);
                ($this->emitter)(new SubAgentActivity(SubAgentActivity::OP_PROGRESS, $id, $this->name, '', 2, '-> Read', 77));
                if ($this->dieAfterProgress && getmypid() !== $this->parent) {
                    // Gone without a finished beat and without a result file.
                    ForkedChild::exitNow(1);
                }
                ($this->emitter)(new SubAgentActivity(SubAgentActivity::OP_FINISHED, $id, $this->name, '', 3, 'report', 77));

                return new ToolResult(toolCallId: (string) ($args['id'] ?? ''), content: $this->name . ' done');
            }

            public function isParallelSafe(): bool
            {
                return true;
            }

            public function subAgentEmitter(): ?\Closure
            {
                return $this->emitter;
            }

            public function withActivitySink(ActivitySink $sink): Tool
            {
                return new self($this->name, static function (SubAgentActivity $beat) use ($sink): void {
                    $sink->emit($beat);
                }, $this->dieAfterProgress, $this->holdMicros, $this->parent);
            }
        };
    }
}

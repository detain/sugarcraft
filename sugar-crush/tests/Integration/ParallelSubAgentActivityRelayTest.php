<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Events\SubAgentActivity;
use SugarCraft\Crush\Events\ToolFinished;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Tools\ParallelSafe;
use SugarCraft\Crush\Tools\RelaysSubAgentActivity;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * A batch of parallel Task calls must still put a row in the Agents pane.
 *
 * {@see Runtime::executeConcurrently()} runs each member in a forked child,
 * and the emitter a Task call is bound with refuses to write from any process
 * but the turn's own — so before the relay, every beat of a parallel
 * delegation was dropped and only a lone Task call (run in-process) ever
 * appeared on the dashboard. The witness here is the PID the bound emitter
 * observes: it must be the parent's, for every beat of every member.
 */
final class ParallelSubAgentActivityRelayTest extends TestCase
{
    use \SugarCraft\Crush\Tests\Support\ReapsForkedChildrenTrait;

    protected function setUp(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('Concurrent tool dispatch requires ext-pcntl.');
        }
    }

    public function testEveryForkedMembersBeatsReachTheBoundEmitterInTheParent(): void
    {
        $parent = getmypid();
        /** @var list<array{pid: int|false, beat: SubAgentActivity}> $seen */
        $seen = [];
        $timeline = [];
        $emitter = static function (SubAgentActivity $beat) use (&$seen, &$timeline): void {
            $seen[] = ['pid' => getmypid(), 'beat' => $beat];
            $timeline[] = $beat->op . ':' . $beat->id;
        };
        $onEvent = static function (object $event) use (&$timeline): void {
            if ($event instanceof ToolFinished) {
                $timeline[] = 'tool-finished:' . $event->toolName;
            }
        };

        $provider = $this->createMock(ProviderInterface::class);
        $runtime = new Runtime($provider, new HookManager(new HookRegistry()), null, true);
        $app = App::new($provider, 'gpt-4')->withTools([
            self::delegator('task_a', $emitter),
            self::delegator('task_b', $emitter),
        ]);

        $method = new \ReflectionMethod($runtime, 'executeToolCalls');
        /** @var list<ToolResultMessage> $results */
        $results = array_values(iterator_to_array($method->invoke(
            $runtime,
            [new ToolCall('call_a', 'task_a', []), new ToolCall('call_b', 'task_b', [])],
            $app,
            $onEvent,
            null,
            null,
        )));

        $this->assertCount(2, $results);
        $this->assertFalse($results[0]->isError(), $results[0]->content());
        $this->assertFalse($results[1]->isError(), $results[1]->content());

        $this->assertCount(6, $seen, 'started + progress + finished from each of the two members');
        foreach ($seen as $entry) {
            $this->assertSame($parent, $entry['pid'], 'a relayed beat is replayed in the forking process');
        }

        $ids = array_values(array_unique(array_map(static fn (array $e): string => $e['beat']->id, $seen)));
        sort($ids);
        $this->assertSame(['task_a-run', 'task_b-run'], $ids);

        foreach (['task_a', 'task_b'] as $name) {
            $ops = array_values(array_map(
                static fn (array $e): string => $e['beat']->op,
                array_filter($seen, static fn (array $e): bool => $e['beat']->name === $name),
            ));
            $this->assertSame(
                [SubAgentActivity::OP_STARTED, SubAgentActivity::OP_PROGRESS, SubAgentActivity::OP_FINISHED],
                $ops,
                "{$name}'s beats arrive whole and in order",
            );
            $this->assertLessThan(
                array_search('tool-finished:' . $name, $timeline, true),
                array_search('finished:' . $name . '-run', $timeline, true),
                "{$name}'s finished beat lands before its ToolFinished, so the row settles first",
            );
        }

        $started = array_values(array_filter($seen, static fn (array $e): bool => $e['beat']->op === SubAgentActivity::OP_STARTED));
        $this->assertSame('look over ' . $started[0]['beat']->name, $started[0]['beat']->task, 'the payload survives the relay');
    }

    /**
     * A parallel-safe stand-in for TaskTool: emits a run's three beats from
     * wherever it executes, through whatever emitter it is bound with.
     */
    private static function delegator(string $name, \Closure $emitter): Tool
    {
        return new class ($name, $emitter) implements Tool, ParallelSafe, RelaysSubAgentActivity {
            public function __construct(private string $name, private \Closure $emitter)
            {
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
                ($this->emitter)(new SubAgentActivity(SubAgentActivity::OP_STARTED, $id, $this->name, 'look over ' . $this->name, 1, ''));
                usleep(50_000);
                ($this->emitter)(new SubAgentActivity(SubAgentActivity::OP_PROGRESS, $id, $this->name, '', 2, '-> Read'));
                usleep(50_000);
                ($this->emitter)(new SubAgentActivity(SubAgentActivity::OP_FINISHED, $id, $this->name, '', 3, 'report'));

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

            public function withSubAgentEmitter(\Closure $emitter): Tool
            {
                return new self($this->name, $emitter);
            }
        };
    }
}

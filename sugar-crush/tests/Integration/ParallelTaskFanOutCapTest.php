<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentPoolConfig;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Tools\ExemptFromParallelDeadline;
use SugarCraft\Crush\Tools\ParallelSafe;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Step 0.16: {@see Runtime::executeConcurrently()} runs at most
 * `maxConcurrentDelegations` {@see ExemptFromParallelDeadline} members (a
 * delegated `Task`) at once — default {@see AgentPoolConfig::$maxConcurrent}
 * — and forks the rest, in provider order, as running ones exit. Seconds-scale
 * siblings are never queued behind a delegation slot.
 *
 * Every member stamps its start and end time into a shared directory from
 * inside its forked child, so the overlap is measured, not inferred from
 * wall-clock totals.
 */
final class ParallelTaskFanOutCapTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('Concurrent tool dispatch requires ext-pcntl.');
        }

        $this->dir = sys_get_temp_dir() . '/fanout-cap-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o700, true);
    }

    protected function tearDown(): void
    {
        // An abandoned group leaves its running member alone by design; wait
        // it out here so no orphan writes into the next test's tree.
        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline && pcntl_waitpid(-1, $status, WNOHANG) > 0) {
            // reaped one; keep going
        }
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    public function testAtMostTheCapOfDelegatedRunsAreAliveAtOnce(): void
    {
        $names = ['t0', 't1', 't2', 't3', 't4'];
        $results = $this->runGroup(2, array_map(fn(string $n): Tool => $this->member($n, 0.4, exempt: true), $names));

        $this->assertSame(array_map(static fn(string $n): string => "{$n} done", $names), array_map(
            static fn(ToolResultMessage $r): string => $r->content(),
            $results,
        ), 'every member ran, and results are released in provider order');
        $this->assertSame(2, $this->maxOverlap($names), 'two slots: never more than two alive, and both slots used');
        $this->assertTrue(
            $this->stamp('t0', 'start') < $this->stamp('t2', 'start') && $this->stamp('t1', 'start') < $this->stamp('t3', 'start'),
            'queued members start in provider order',
        );
    }

    public function testTheDefaultCapIsTheAgentPoolConfigDefault(): void
    {
        $width = (new AgentPoolConfig())->maxConcurrent;
        $names = array_map(static fn(int $i): string => "d{$i}", range(0, $width));
        $this->runGroup(null, array_map(fn(string $n): Tool => $this->member($n, 0.5, exempt: true), $names));

        $this->assertSame($width, $this->maxOverlap($names));
    }

    public function testASecondsScaleSiblingIsNeverQueuedBehindADelegationSlot(): void
    {
        $results = $this->runGroup(1, [
            $this->member('task_a', 0.6, exempt: true),
            $this->member('task_b', 0.6, exempt: true),
            $this->member('read', 0.05, exempt: false),
        ]);

        $this->assertCount(3, $results);
        $this->assertLessThan(
            $this->stamp('task_a', 'end'),
            $this->stamp('read', 'start'),
            'the plain sibling ran beside the first delegation, not after the queue drained',
        );
        $this->assertGreaterThanOrEqual(
            $this->stamp('task_a', 'end'),
            $this->stamp('task_b', 'start'),
            'one slot: the second delegation waited for the first to exit',
        );
    }

    public function testAnAbandonedGroupNeverStartsADelegationStillQueued(): void
    {
        $runtime = new Runtime($this->createMock(ProviderInterface::class), new HookManager(new HookRegistry()), maxConcurrentDelegations: 1);
        $tools = [$this->member('q0', 0.2, exempt: true), $this->member('q1', 0.2, exempt: true), $this->member('q2', 0.2, exempt: true)];
        $app = App::new($this->createMock(ProviderInterface::class), 'gpt-4')->withTools($tools);

        $generator = (new \ReflectionMethod($runtime, 'executeToolCalls'))->invoke(
            $runtime,
            [new ToolCall('c0', 'q0', []), new ToolCall('c1', 'q1', []), new ToolCall('c2', 'q2', [])],
            $app,
            null,
            null,
            null,
        );
        $first = $generator->current();
        $this->assertInstanceOf(ToolResultMessage::class, $first);
        $this->assertSame('q0 done', $first->content());

        // The consumer walks away with q1 running and q2 still queued.
        unset($generator);
        gc_collect_cycles();
        usleep(800_000);

        $this->assertFileExists($this->dir . '/q1.start', 'q1 took the slot q0 freed');
        $this->assertFileDoesNotExist($this->dir . '/q2.start', 'a queued delegation must not start after its group was abandoned');
    }

    /**
     * @param list<Tool> $tools
     * @return list<ToolResultMessage>
     */
    private function runGroup(?int $cap, array $tools): array
    {
        $provider = $this->createMock(ProviderInterface::class);
        $runtime = new Runtime($provider, new HookManager(new HookRegistry()), maxConcurrentDelegations: $cap);
        $app = App::new($provider, 'gpt-4')->withTools($tools);

        $calls = array_map(static fn(Tool $t): ToolCall => new ToolCall('call_' . $t->name(), $t->name(), []), $tools);

        return array_values(iterator_to_array((new \ReflectionMethod($runtime, 'executeToolCalls'))->invoke(
            $runtime,
            $calls,
            $app,
            null,
            null,
            null,
        ), false));
    }

    /** @param list<string> $names */
    private function maxOverlap(array $names): int
    {
        $edges = [];
        foreach ($names as $name) {
            $edges[] = [$this->stamp($name, 'start'), 1];
            $edges[] = [$this->stamp($name, 'end'), -1];
        }
        // An end and a start at the same instant do not overlap.
        usort($edges, static fn(array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        $alive = 0;
        $max = 0;
        foreach ($edges as [, $delta]) {
            $alive += $delta;
            $max = max($max, $alive);
        }

        return $max;
    }

    private function stamp(string $name, string $edge): float
    {
        $path = "{$this->dir}/{$name}.{$edge}";
        $this->assertFileExists($path);

        return (float) file_get_contents($path);
    }

    private function member(string $name, float $seconds, bool $exempt): Tool
    {
        $dir = $this->dir;

        return $exempt
            ? new class ($name, $seconds, $dir) implements Tool, ParallelSafe, ExemptFromParallelDeadline {
                public function __construct(private string $name, private float $seconds, private string $dir)
                {
                }

                public function name(): string
                {
                    return $this->name;
                }

                public function description(): string
                {
                    return 'stamps, sleeps, stamps';
                }

                public function inputSchema(): array
                {
                    return ['type' => 'object', 'properties' => []];
                }

                public function execute(array $args): ToolResult
                {
                    file_put_contents("{$this->dir}/{$this->name}.start", sprintf('%.6F', microtime(true)));
                    usleep((int) ($this->seconds * 1_000_000));
                    file_put_contents("{$this->dir}/{$this->name}.end", sprintf('%.6F', microtime(true)));

                    return new ToolResult(toolCallId: '', content: $this->name . ' done');
                }

                public function isParallelSafe(): bool
                {
                    return true;
                }
            }
            : new class ($name, $seconds, $dir) implements Tool, ParallelSafe {
                public function __construct(private string $name, private float $seconds, private string $dir)
                {
                }

                public function name(): string
                {
                    return $this->name;
                }

                public function description(): string
                {
                    return 'stamps, sleeps, stamps';
                }

                public function inputSchema(): array
                {
                    return ['type' => 'object', 'properties' => []];
                }

                public function execute(array $args): ToolResult
                {
                    file_put_contents("{$this->dir}/{$this->name}.start", sprintf('%.6F', microtime(true)));
                    usleep((int) ($this->seconds * 1_000_000));
                    file_put_contents("{$this->dir}/{$this->name}.end", sprintf('%.6F', microtime(true)));

                    return new ToolResult(toolCallId: '', content: $this->name . ' done');
                }

                public function isParallelSafe(): bool
                {
                    return true;
                }
            };
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Integration;

use PHPUnit\Framework\TestCase;
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
 * {@see Runtime}'s parallel-tool deadline is PER JOB: a tool declaring
 * {@see ExemptFromParallelDeadline} (a delegated `Task`, whose body is a whole
 * agentic run) outlives the group deadline, while a seconds-scale sibling in
 * the same group is still SIGKILLed at it — and the parent keeps signalling
 * liveness through the heartbeat for as long as it waits, so the turn's own
 * idle ceiling does not kill the run the deadline spared.
 */
final class ParallelDeadlineExemptionTest extends TestCase
{
    protected function setUp(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('Concurrent tool dispatch requires ext-pcntl.');
        }
    }

    public function testAnExemptJobOutlivesTheGroupDeadlineWhileItsSiblingIsStillKilled(): void
    {
        $provider = $this->createMock(ProviderInterface::class);
        $runtime = new Runtime($provider, new HookManager(new HookRegistry()), null, true, 1);
        $app = App::new($provider, 'gpt-4')->withTools([self::sleeper('long_run', 2.5, exempt: true), self::sleeper('hang', 30.0)]);

        $beats = 0;
        $heartbeat = static function () use (&$beats): void {
            $beats++;
        };

        $method = new \ReflectionMethod($runtime, 'executeToolCalls');
        $started = microtime(true);
        /** @var list<ToolResultMessage> $results */
        $results = array_values(iterator_to_array($method->invoke(
            $runtime,
            [new ToolCall('call_a', 'long_run', []), new ToolCall('call_b', 'hang', [])],
            $app,
            null,
            null,
            $heartbeat,
        )));
        $elapsed = microtime(true) - $started;

        $this->assertFalse($results[0]->isError(), 'the exempt job must not be killed at the 1s deadline');
        $this->assertSame('long_run done', $results[0]->content());
        $this->assertTrue($results[1]->isError());
        $this->assertStringContainsString('killed at the 1s parallel-tool deadline', $results[1]->content());
        $this->assertGreaterThanOrEqual(2.5, $elapsed, 'the group waited the exempt job out');
        $this->assertLessThan(20.0, $elapsed, 'the hung sibling was still bounded by the deadline');
        $this->assertGreaterThanOrEqual(1, $beats, 'the parent signals liveness while it waits on the group');
    }

    private static function sleeper(string $name, float $seconds, bool $exempt = false): Tool
    {
        return $exempt
            ? new class ($name, $seconds) implements Tool, ParallelSafe, ExemptFromParallelDeadline {
                public function __construct(private string $name, private float $seconds)
                {
                }

                public function name(): string
                {
                    return $this->name;
                }

                public function description(): string
                {
                    return 'sleeps, then answers';
                }

                public function inputSchema(): array
                {
                    return ['type' => 'object', 'properties' => []];
                }

                public function execute(array $args): ToolResult
                {
                    usleep((int) ($this->seconds * 1_000_000));

                    return new ToolResult(toolCallId: (string) ($args['id'] ?? ''), content: $this->name . ' done');
                }

                public function isParallelSafe(): bool
                {
                    return true;
                }
            }
        : new class ($name, $seconds) implements Tool, ParallelSafe {
            public function __construct(private string $name, private float $seconds)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function description(): string
            {
                return 'sleeps, then answers';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): ToolResult
            {
                usleep((int) ($this->seconds * 1_000_000));

                return new ToolResult(toolCallId: (string) ($args['id'] ?? ''), content: $this->name . ' done');
            }

            public function isParallelSafe(): bool
            {
                return true;
            }
        };
    }
}

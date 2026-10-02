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
use SugarCraft\Crush\Support\ProcessContainment;
use SugarCraft\Crush\Support\ProcessTree;
use SugarCraft\Crush\Tests\Support\ProcessTreeKillTest;
use SugarCraft\Crush\Tools\BuiltIn\Bash;
use SugarCraft\Crush\Tools\ParallelSafe;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Audit B2/F-E2, the Runtime half: a parallel job killed at the group deadline
 * takes the command it was running with it. Before the fix the deadline
 * SIGKILLed the job's forked PHP child only, and its setsid'd command (a
 * `Grep`'s `rg`, any ParallelSafe tool's shell-out) ran on as an orphan.
 */
final class ParallelDeadlineKillsCommandTreeTest extends TestCase
{
    /** @var list<int> */
    private array $strays = [];

    protected function setUp(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            self::markTestSkipped('Concurrent tool dispatch requires ext-pcntl and ext-posix.');
        }
        if (!ProcessTree::available() || ProcessContainment::detachedSpawnBinary() === '') {
            self::markTestSkipped('the tree walk needs /proc and the orphan needs a setsid-detached command.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->strays as $pid) {
            @\posix_kill($pid, 9);
        }

        parent::tearDown();
    }

    public function testAJobKilledAtTheDeadlineTakesItsCommandWithIt(): void
    {
        $marker = 'sleep 30.' . \random_int(100000, 999999);
        $provider = $this->createMock(ProviderInterface::class);
        $runtime = new Runtime($provider, new HookManager(new HookRegistry()), null, true, 1);
        $app = App::new($provider, 'gpt-4')->withTools([self::shellOut('slow_a', $marker), self::shellOut('slow_b', $marker)]);

        $method = new \ReflectionMethod($runtime, 'executeToolCalls');
        /** @var list<ToolResultMessage> $results */
        $results = \array_values(\iterator_to_array($method->invoke(
            $runtime,
            [new ToolCall('call_a', 'slow_a', []), new ToolCall('call_b', 'slow_b', [])],
            $app,
            null,
            null,
            null,
        )));

        self::assertTrue($results[0]->isError());
        self::assertStringContainsString('killed at the 1s parallel-tool deadline', $results[0]->content());

        $deadline = \microtime(true) + 2.0;
        do {
            $survivors = ProcessTreeKillTest::pidsRunning($marker);
            \usleep(20_000);
        } while ($survivors !== [] && \microtime(true) < $deadline);
        \array_push($this->strays, ...$survivors);

        self::assertSame([], $survivors, 'a deadline-killed job\'s command outlived it');
    }

    private static function shellOut(string $name, string $command): Tool
    {
        return new class ($name, $command) implements Tool, ParallelSafe {
            public function __construct(private string $name, private string $command)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function description(): string
            {
                return 'runs a long command through the containment spawn';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): ToolResult
            {
                return (new Bash())->execute(['command' => $this->command]);
            }

            public function isParallelSafe(): bool
            {
                return true;
            }
        };
    }
}

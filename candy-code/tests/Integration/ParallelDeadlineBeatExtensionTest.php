<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\App\App;
use SugarCraft\Code\Hooks\HookManager;
use SugarCraft\Code\Hooks\HookRegistry;
use SugarCraft\Code\Messages\ToolResultMessage;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Runtime;
use SugarCraft\Code\Tools\AcceptsHeartbeat;
use SugarCraft\Code\Tools\ParallelSafe;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Tools\ToolCall;
use SugarCraft\Code\Tools\ToolResult;

/**
 * A sibling that is still making progress outlives the 1s group deadline, and
 * one that is silent does not. The beat is the only signal the parent has, so
 * these tests pin both halves: a beating call finishes with its real result,
 * and a silent call is reported with its id and how long it ran.
 */
final class ParallelDeadlineBeatExtensionTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\function_exists('pcntl_fork')) {
            self::markTestSkipped('Concurrent tool dispatch requires ext-pcntl.');
        }
    }

    public function testABeatingSiblingOutlivesTheDeadlineAndKeepsItsResult(): void
    {
        $results = $this->runGroup([
            'call_beats' => self::beating('slow_beats', 3.0),
            'call_quiet' => self::silent('slow_silent', 3.0),
        ]);

        self::assertFalse($results['call_beats']->isError(), 'a sibling still beating must not be killed at the 1s deadline');
        self::assertSame('finished after 3s of work', $results['call_beats']->content());
        self::assertTrue($results['call_quiet']->isError());
        self::assertStringContainsString('killed at the 1s parallel-tool deadline', $results['call_quiet']->content());
        self::assertStringContainsString('(call call_quiet)', $results['call_quiet']->content());
        self::assertMatchesRegularExpression('/after running for \d+\.\ds/', $results['call_quiet']->content());
    }

    /**
     * @param array<string, Tool> $tools call id => tool
     * @return array<string, ToolResultMessage> keyed by call id
     */
    private function runGroup(array $tools): array
    {
        $provider = $this->createMock(ProviderInterface::class);
        $runtime = new Runtime($provider, new HookManager(new HookRegistry()), null, true, 1);
        $app = App::new($provider, 'gpt-4')->withTools(array_values($tools));
        $calls = [];
        foreach ($tools as $id => $tool) {
            $calls[] = new ToolCall($id, $tool->name(), []);
        }
        $method = new \ReflectionMethod($runtime, 'executeToolCalls');
        /** @var list<ToolResultMessage> $messages */
        $messages = \array_values(\iterator_to_array($method->invoke($runtime, $calls, $app, null, null, null)));

        $byId = [];
        foreach ($messages as $message) {
            $byId[$message->toolCallId()] = $message;
        }

        return $byId;
    }

    private static function beating(string $name, float $seconds): Tool
    {
        return new class ($name, $seconds) implements Tool, ParallelSafe, AcceptsHeartbeat {
            public function __construct(private string $name, private float $seconds)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function description(): string
            {
                return 'Beats while it works.';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): ToolResult
            {
                return $this->executeWithHeartbeat($args, static function (): void {
                });
            }

            public function executeWithHeartbeat(array $args, \Closure $heartbeat): ToolResult
            {
                $until = \microtime(true) + $this->seconds;
                while (\microtime(true) < $until) {
                    $heartbeat();
                    \usleep(100_000);
                }

                return new ToolResult(toolCallId: '', content: 'finished after 3s of work');
            }

            public function isParallelSafe(): bool
            {
                return true;
            }
        };
    }

    private static function silent(string $name, float $seconds): Tool
    {
        return new class ($name, $seconds) implements Tool, ParallelSafe {
            public function __construct(private string $name, private float $seconds)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function description(): string
            {
                return 'Never reports progress.';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $args): ToolResult
            {
                \usleep((int) ($this->seconds * 1_000_000));

                return new ToolResult(toolCallId: '', content: 'too late');
            }

            public function isParallelSafe(): bool
            {
                return true;
            }
        };
    }
}

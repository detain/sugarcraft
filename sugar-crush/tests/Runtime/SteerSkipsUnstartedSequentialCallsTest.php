<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Backend\TurnInbox;
use SugarCraft\Crush\Events\ToolFinished;
use SugarCraft\Crush\Events\ToolStarted;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Roadmap 1.C-3: once a mid-turn message is waiting, the step's sequential
 * calls that have not started are answered "Skipped to process an incoming
 * message." instead of run — a call already running finishes — and the
 * message itself is left for the step loop to deliver.
 */
final class SteerSkipsUnstartedSequentialCallsTest extends TestCase
{
    public function testCallsAfterTheMessageArrivesAreSkippedNotRun(): void
    {
        $ran = [];
        $inbox = self::inbox();
        $tools = [
            // The first call is running when the user presses Enter.
            self::tool('first', function () use (&$ran, $inbox): void {
                $ran[] = 'first';
                $inbox->waiting = true;
            }),
            self::tool('second', function () use (&$ran): void {
                $ran[] = 'second';
            }),
            self::tool('third', function () use (&$ran): void {
                $ran[] = 'third';
            }),
        ];

        $events = [];
        $results = $this->dispatch(
            [new ToolCall('c1', 'first', []), new ToolCall('c2', 'second', []), new ToolCall('c3', 'third', [])],
            $tools,
            $inbox,
            static function (object $event) use (&$events): void {
                $events[] = $event::class . ':' . $event->toolCallId;
            },
        );

        $this->assertSame(['first'], $ran, 'the running call finished; nothing after it started');
        $this->assertSame(['c1', 'c2', 'c3'], array_map(static fn (ToolResultMessage $r): string => $r->toolCallId(), $results), 'every call is still answered, in order');
        $this->assertFalse($results[0]->isError());
        foreach ([1, 2] as $i) {
            $this->assertSame(TurnInbox::SKIPPED, $results[$i]->content());
            $this->assertTrue($results[$i]->isError(), 'an error result, so nothing reads the call as having run');
        }
        $this->assertSame([
            ToolStarted::class . ':c1', ToolFinished::class . ':c1',
            ToolStarted::class . ':c2', ToolFinished::class . ':c2',
            ToolStarted::class . ':c3', ToolFinished::class . ':c3',
        ], $events, 'a skipped call keeps its started/finished pair');
        $this->assertSame(0, $inbox->drained, 'the probe never consumes the message');
    }

    public function testAMessageWaitingBeforeTheFirstCallSkipsTheWholeStep(): void
    {
        $ran = [];
        $inbox = self::inbox();
        $inbox->waiting = true;

        $results = $this->dispatch(
            [new ToolCall('c1', 'first', [])],
            [self::tool('first', function () use (&$ran): void {
                $ran[] = 'first';
            })],
            $inbox,
        );

        $this->assertSame([], $ran);
        $this->assertSame(TurnInbox::SKIPPED, $results[0]->content());
    }

    public function testWithNothingWaitingEveryCallRuns(): void
    {
        $ran = [];
        $results = $this->dispatch(
            [new ToolCall('c1', 'first', []), new ToolCall('c2', 'second', [])],
            [
                self::tool('first', function () use (&$ran): void {
                    $ran[] = 'first';
                }),
                self::tool('second', function () use (&$ran): void {
                    $ran[] = 'second';
                }),
            ],
            self::inbox(),
        );

        $this->assertSame(['first', 'second'], $ran);
        $this->assertSame(['ok', 'ok'], array_map(static fn (ToolResultMessage $r): string => $r->content(), $results));
    }

    /**
     * @param list<ToolCall> $calls
     * @param list<Tool>     $tools
     * @return list<ToolResultMessage>
     */
    private function dispatch(array $calls, array $tools, TurnInbox $inbox, ?\Closure $onEvent = null): array
    {
        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('steer-stub');
        $runtime = (new Runtime($provider, new HookManager(new HookRegistry())))->withTurnInbox($inbox);
        $app = App::new($provider, 'm')->withTools($tools);

        $method = new \ReflectionMethod(Runtime::class, 'executeToolCalls');

        return array_values(iterator_to_array($method->invoke($runtime, $calls, $app, $onEvent, null, null), false));
    }

    private static function inbox(): TurnInbox
    {
        return new class () implements TurnInbox {
            public bool $waiting = false;

            public int $drained = 0;

            public function drain(int $step): array
            {
                $this->drained++;

                return [];
            }

            public function pending(): bool
            {
                return $this->waiting;
            }
        };
    }

    private static function tool(string $name, \Closure $run): Tool
    {
        return new class ($name, $run) implements Tool {
            public function __construct(private readonly string $toolName, private readonly \Closure $run)
            {
            }

            public function name(): string
            {
                return $this->toolName;
            }

            public function description(): string
            {
                return $this->toolName;
            }

            public function inputSchema(): array
            {
                return ['type' => 'object'];
            }

            public function execute(array $args): ToolResult
            {
                ($this->run)();

                return new ToolResult(toolCallId: '', content: 'ok');
            }
        };
    }
}

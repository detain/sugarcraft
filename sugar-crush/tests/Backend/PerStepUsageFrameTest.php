<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Events\StepStarted;
use SugarCraft\Crush\Events\UsageUpdated;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Tests\Backend\Support\InteractiveTurnHarness;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;
use SugarCraft\Crush\Usage;

/**
 * Roadmap 1.C-4: a forked turn reports each step as it starts (`step` frame →
 * {@see StepStarted}, with the step's context pressure) and each response as
 * it is billed (`usage` frame → {@see UsageUpdated}, with the turn's running
 * total), so the UI can move the step counter and the spend while the turn
 * runs instead of learning both from the `result` frame.
 */
final class PerStepUsageFrameTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('pcntl_waitpid')) {
            self::markTestSkipped('the frames cross completeAsync()\'s fork, which needs ext-pcntl.');
        }
    }

    public function testEveryStepAndEveryBillCrossTheForkInTurnOrder(): void
    {
        $seen = [];
        $state = InteractiveTurnHarness::settle(
            self::backend()->completeAsync([Message::user('go')], onStep: static function (StepStarted|UsageUpdated $e) use (&$seen): void {
                $seen[] = $e;
            }),
            Loop::get(),
        );

        self::assertTrue($state['settled'], 'the turn never settled');
        self::assertNull($state['error']);
        self::assertSame('done', $state['value']->content);

        self::assertSame(
            [StepStarted::class . '#1', UsageUpdated::class . '#1', StepStarted::class . '#2', UsageUpdated::class . '#2', StepStarted::class . '#3', UsageUpdated::class . '#3'],
            array_map(static fn (StepStarted|UsageUpdated $e): string => $e::class . '#' . $e->step, $seen),
        );

        $first = $seen[0];
        self::assertInstanceOf(StepStarted::class, $first);
        self::assertSame(10, $first->maxSteps);
        self::assertNotNull($first->pressure, 'the step frame carries the context pressure');
        self::assertSame(200_000, $first->pressure->window);
        self::assertGreaterThan(0, $first->pressure->toolTokens);

        $bills = array_values(array_filter($seen, static fn ($e): bool => $e instanceof UsageUpdated));
        self::assertEqualsWithDelta(0.01, $bills[0]->usage?->costUsd, 1e-9);
        self::assertEqualsWithDelta([0.01, 0.03, 0.06], array_map(static fn (UsageUpdated $u): float => $u->turnUsage?->costUsd ?? -1.0, $bills), 1e-9, 'the running total, step by step');
        self::assertEqualsWithDelta(0.06, $state['value']->usage?->costUsd, 1e-9, 'and it agrees with the settled reply');
    }

    public function testAnInteractiveTurnReportsTheSameFrames(): void
    {
        $seen = [];
        $state = InteractiveTurnHarness::settle(
            self::backend()->completeInteractive([Message::user('go')], onEvent: static function (object $e): void {
            }, onStep: static function (StepStarted|UsageUpdated $e) use (&$seen): void {
                $seen[] = $e;
            }),
            Loop::get(),
        );

        self::assertNull($state['error']);
        self::assertCount(6, $seen);
    }

    public function testNoObserverChangesNothing(): void
    {
        $state = InteractiveTurnHarness::settle(self::backend()->completeAsync([Message::user('go')]), Loop::get());

        self::assertNull($state['error']);
        self::assertSame('done', $state['value']->content);
    }

    public function testTheFrameCodecRefusesShapesItDidNotWrite(): void
    {
        $step = new StepStarted(3, 9);
        self::assertEquals($step, StepStarted::fromArray(unserialize(serialize($step->toArray()), ['allowed_classes' => false])));
        self::assertNull(StepStarted::fromArray(['step' => '3', 'maxSteps' => 9]));
        self::assertNull(StepStarted::fromArray(null));

        $bill = new UsageUpdated(2, Usage::new(10, 0.5), null);
        self::assertEquals($bill, UsageUpdated::fromArray(unserialize(serialize($bill->toArray()), ['allowed_classes' => false])));
        self::assertNull(UsageUpdated::fromArray(['usage' => []]));
    }

    private static function backend(): EngineBackend
    {
        $script = [];
        foreach ([1, 2] as $n) {
            $script[] = new CompleteResponse(
                content: "step {$n}",
                toolCalls: [new ToolCall("c{$n}", 'probe', ['n' => $n])],
                tokensUsed: 100 * $n,
                costUsd: 0.01 * $n,
                usage: Usage::new(100 * $n, 0.01 * $n, 90 * $n, 10, 0),
            );
        }
        $script[] = new CompleteResponse(content: 'done', tokensUsed: 300, costUsd: 0.03, usage: Usage::new(300, 0.03, 290, 10, 0));

        return EngineBackend::new(new ScriptedProvider($script, contextWindow: 200_000), 'm')
            ->withTools([self::probe()])
            ->withMaxSteps(10);
    }

    private static function probe(): Tool
    {
        return new class implements Tool {
            public function name(): string { return 'probe'; }

            public function description(): string { return 'answers'; }

            public function inputSchema(): array { return ['type' => 'object', 'properties' => []]; }

            public function execute(array $args): ToolResult
            {
                return new ToolResult(toolCallId: '', content: 'ok');
            }
        };
    }
}

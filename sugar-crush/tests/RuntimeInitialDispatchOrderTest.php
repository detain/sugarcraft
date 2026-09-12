<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Events\ToolFinished;
use SugarCraft\Crush\Events\ToolStarted;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * L137 regression: turn dispatch has exactly ONE path, and it is pull-ordered.
 *
 * WHAT THE ENTRY SAID: that Runtime's `addInitialCommand()` calls bypass
 * Runtime's own command queue, prescribing that initial commands be routed
 * through the same queue/inbox path every other command takes.
 *
 * WHAT THE TREE MEASURES at b001ce7be: there is no `addInitialCommand` symbol
 * anywhere — not in `src/`, not in `tests/`, not in the docs — and
 * {@see Runtime} carries no queue, inbox, or arm surface at all: the class has
 * zero queue-shaped symbols and its constructor takes provider, hooks, and two
 * knobs. The entry's mechanism is gone; what REPLACED it is the generator
 * itself. {@see Runtime::run()} assembles the turn's initial content
 * synchronously (`buildMessages()`, `assembleSections()`), yields the
 * assistant message, and dispatches every tool call only as the CONSUMER
 * advances the generator past that yield — `runBatch()` yields
 * {@see AssistantMessage} before it iterates {@see \SugarCraft\Crush\Runtime::executeToolCalls()}, and
 * PHP generators do not run what they have not been pulled to. Dispatch order
 * is provider order by construction (see the executeToolCalls doc-block).
 *
 * That is the same discipline this repo's live inbox applies on the TEA side
 * (`RuntimeNoticeSink` → `RuntimeNoticePumpMsg` → `Chat::update()`): every
 * wake-up enters through ONE ordered inbox rather than a side door. This file
 * pins the three ordering semantics that make the invariant visible to a
 * regression, so a future side-channel — anything that starts dispatch before
 * or besides the pulled stream — goes red here rather than racing silently:
 *
 *  1. nothing dispatches while the consumer still holds the initial turn;
 *  2. abandoning the stream after the initial turn dispatches NOTHING;
 *  3. a fully consumed stream dispatches in provider order.
 *
 * ⚠️ The brief's pointer at an "update-recursive pattern tests cite (~:1419)"
 * did not reproduce at this base — no test cites that line — so these pins
 * mirror the live single-path shape above instead of a dead reference.
 *
 * @see Runtime
 */
final class RuntimeInitialDispatchOrderTest extends TestCase
{
    private ProviderInterface $provider;
    private Runtime $runtime;

    /** @var list<string> tool names whose execute() body actually ran */
    private array $ranTools = [];

    protected function setUp(): void
    {
        $this->provider = $this->createMock(ProviderInterface::class);
        $this->provider->method('name')->willReturn('test-provider');

        $this->runtime = new Runtime(
            $this->provider,
            new HookManager(new HookRegistry()),
        );
        $this->ranTools = [];
    }

    public function testNothingDispatchesWhileTheConsumerStillHoldsTheInitialTurn(): void
    {
        $this->provider->method('supportsStreaming')->willReturn(false);
        $this->provider->method('complete')->willReturn(new CompleteResponse(
            content: 'calling',
            toolCalls: [new ToolCall('call_first', 'late_tool', [])],
        ));

        $app = App::new($this->provider, 'gpt-4')
            ->withTools([$this->recordingTool('late_tool')]);

        /** @var list<string> */
        $timeline = [];

        $stream = $this->runtime->run($app, function (object $event) use (&$timeline): void {
            $timeline[] = $event instanceof ToolStarted ? 'started' : 'finished';
        });

        $stream->rewind();

        // The initial turn has been delivered...
        $this->assertInstanceOf(AssistantMessage::class, $stream->current());
        // ...and NOTHING has dispatched yet: not an event, not a tool body.
        // An initial-command side channel bypassing the pulled stream would
        // land bytes here — after the first yield, before the second — and
        // race the consumer's handling of the turn that scheduled them.
        $this->assertSame([], $timeline, 'dispatch must not start before the consumer advances past the initial turn');
        $this->assertSame([], $this->ranTools);

        // Advancing the stream is what dispatches, once, in stream order.
        $stream->next();
        $result = $stream->current();
        $this->assertInstanceOf(ToolResultMessage::class, $result);
        $this->assertSame('call_first', $result->toolCallId());
        $this->assertSame(['started', 'finished'], $timeline);
        $this->assertSame(['late_tool'], $this->ranTools);
    }

    public function testAbandoningTheStreamAfterTheInitialTurnDispatchesNothing(): void
    {
        $this->provider->method('supportsStreaming')->willReturn(false);
        $this->provider->method('complete')->willReturn(new CompleteResponse(
            content: 'calling',
            toolCalls: [new ToolCall('call_dead', 'unrun_tool', [])],
            tokensUsed: 0,
        ));

        $app = App::new($this->provider, 'gpt-4')
            ->withTools([$this->recordingTool('unrun_tool')]);

        $seen = [];
        foreach ($this->runtime->run($app) as $message) {
            $seen[] = $message;
            break; // consumer stops at the initial turn, as update() does on a cancelled turn
        }

        $this->assertCount(1, $seen);
        $this->assertInstanceOf(AssistantMessage::class, $seen[0]);
        $this->assertSame([], $this->ranTools, 'a turn abandoned at the initial message must leave its commands undispatched');
    }

    public function testAFullyConsumedStreamDispatchesInProviderOrder(): void
    {
        $this->provider->method('supportsStreaming')->willReturn(false);
        $this->provider->method('complete')->willReturn(new CompleteResponse(
            content: '',
            // provider order [b, a] — deliberately NOT alphabetical/insertional
            toolCalls: [
                new ToolCall('call_b', 'tool_b', []),
                new ToolCall('call_a', 'tool_a', []),
            ],
            tokensUsed: 0,
        ));

        $app = App::new($this->provider, 'gpt-4')
            ->withTools([
                $this->recordingTool('tool_b'),
                $this->recordingTool('tool_a'),
            ]);

        $events = [];
        $messages = iterator_to_array($this->runtime->run($app, function (object $event) use (&$events): void {
            $events[] = $event;
        }));

        $this->assertInstanceOf(AssistantMessage::class, $messages[0]);
        $this->assertSame(
            ['call_b', 'call_a'],
            array_map(
                static fn (ToolResultMessage $m): string => $m->toolCallId(),
                array_slice($messages, 1),
            ),
        );
        $this->assertSame(
            [ToolStarted::class, ToolFinished::class, ToolStarted::class, ToolFinished::class],
            array_map(static fn (object $e): string => $e::class, $events),
        );
        $this->assertSame(['call_b', 'call_a'], array_map(
            static fn (ToolStarted $e): string => $e->toolCallId,
            array_values(array_filter($events, static fn (object $e): bool => $e instanceof ToolStarted)),
        ));
        $this->assertSame(['tool_b', 'tool_a'], $this->ranTools);
    }

    /**
     * A Tool mock that records — in the parent's own process — whether its
     * body ran, which is the question every pin here asks. The id it returns
     * is invented on purpose, exactly as `RuntimeTest::createMockTool()` does:
     * `settle()` rewrites it to the model's id, and these tests read the
     * stream's ids to prove that rewrite ordering too.
     */
    private function recordingTool(string $name): Tool
    {
        $tool = $this->createMock(Tool::class);
        $tool->method('name')->willReturn($name);
        $tool->method('description')->willReturn("Description for $name");
        $tool->method('inputSchema')->willReturn([]);
        $tool->method('execute')->willReturnCallback(function (array $args) use ($name): ToolResult {
            $this->ranTools[] = $name;

            return new ToolResult(toolCallId: "call_$name", content: "result:$name");
        });

        return $tool;
    }
}

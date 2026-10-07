<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Core\TickRequest;
use SugarCraft\Crush\AssistantMsg;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\BackendToolEventsMsg;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Events\ToolFinished;
use SugarCraft\Crush\Events\ToolStarted;
use SugarCraft\Crush\Host\TurnRunner;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * The blocking fallback (no ext-pcntl, or socketpair/fork failing) runs a
 * whole turn synchronously on the loop thread: every tool event lands in the
 * inbox while the pump and the framerate timer cannot paint, and the settle
 * folded them at Cmd speed — measured, sixteen fold hops in one millisecond,
 * so ALL of the turn's tool rows appeared in a single painted frame (the
 * user-reported "rows all appear at once" symptom).
 *
 * The fix is a stamp and a pace: {@see TurnRunner} marks a settle whose
 * promise had already resolved when the dispatch returned as a REPLAY, and
 * {@see Chat}'s fold chain re-dispatches replay hops one pump interval apart
 * on loop timers, so the screen paints between rows. A headless driver that
 * unwinds the chain synchronously answers the resulting {@see TickRequest}
 * through update()'s passthrough arm and loses nothing but the delay — which
 * is what these pins prove, in both directions.
 *
 * The live pump's walking rows (forked path, events time-separated over the
 * child's socket) are pinned by {@see LiveStepWiringTest}; the
 * permission-pending suppression door is pinned by ChatTest's ask-hook trio
 * and is untouched by either mechanism.
 */
final class ReplayedToolRowCascadeTest extends TestCase
{
    private const GENERATION = 7;

    /**
     * The pump's own cadence, read from its private constant: the replay
     * pace and the live poll are ONE figure, and this pin fails closed if
     * either ever drifts from the other.
     */
    private static function pollSeconds(): float
    {
        $constant = (new \ReflectionClass(Chat::class))->getReflectionConstant('TOOL_EVENT_POLL_SECONDS');
        self::assertNotFalse($constant);

        return (float) $constant->getValue();
    }

    /** @param list<ToolStarted|ToolFinished> $events */
    private function replayQueue(array $events): BackendToolEventsMsg
    {
        return new BackendToolEventsMsg($events, Message::assistant('final reply'), self::GENERATION, null, true);
    }

    /** @return list<string> ids of the history rows still WAITING on their tool (running placeholders) */
    private function runningRowIds(Chat $chat): array
    {
        return array_values(array_filter(
            array_map(static fn (Message $m): ?string => $m->pendingToolCallId, $chat->history),
            static fn (?string $id): bool => $id !== null,
        ));
    }

    private function emptyChat(): Chat
    {
        return new Chat(history: [Message::user('go')], generation: self::GENERATION);
    }

    public function testAReplayedFoldHopIsPacedOnThePollInterval(): void
    {
        [$chat, $cmd] = $this->emptyChat()->update($this->replayQueue([
            new ToolStarted('c1', 'bash', ['command' => 'ls']),
            new ToolStarted('c2', 'bash', ['command' => 'pwd']),
        ]));

        $this->assertSame(['c1'], $this->runningRowIds($chat), 'the first replayed row is already in the transcript');

        $hop = $cmd();
        $this->assertInstanceOf(TickRequest::class, $hop, 'a replay hop waits a frame instead of chaining at Cmd speed');
        $this->assertSame(self::pollSeconds(), $hop->seconds);

        $next = ($hop->produce)();
        $this->assertInstanceOf(BackendToolEventsMsg::class, $next);
        $this->assertSame('c2', $next->events[0]->toolCallId, 'the remaining events ride the paced hop in order');
        $this->assertTrue($next->replay, 'the replay stamp survives every hop of the chain');
    }

    public function testAnOrdinarySettleChainStillDrainsAtCmdSpeed(): void
    {
        $queue = new BackendToolEventsMsg(
            [new ToolStarted('c1', 'bash', ['command' => 'ls']), new ToolFinished('c1', 'bash', new ToolResult('c1', 'ok'))],
            Message::assistant('final reply'),
            self::GENERATION,
        );
        [, $cmd] = $this->emptyChat()->update($queue);

        $produced = $cmd();
        $this->assertInstanceOf(
            BackendToolEventsMsg::class,
            $produced,
            'the live path (and every test-built queue) must keep the synchronous-unwinder contract: '
            . 'no replay stamp means no TickRequest, or apply()-style drivers would stall',
        );
        $this->assertFalse($produced->replay);
    }

    public function testTheTickRequestArmWalksRowsBeforeTheReplyLands(): void
    {
        $events = [
            new ToolStarted('c1', 'bash', ['command' => 'ls']),
            new ToolFinished('c1', 'bash', new ToolResult('c1', 'one')),
            new ToolStarted('c2', 'bash', ['command' => 'pwd']),
        ];
        $chat = $this->emptyChat();
        $msg = $this->replayQueue($events);
        $frames = [];

        // One unwind step = one fold, the shape Program::dispatch gives the
        // chain once the armed timer fires: every event passes through its
        // OWN update()-produced frame, with the reply held back until the
        // queue drains. No wall clock is waited anywhere.
        for ($i = 0; $i < 8 && $msg !== null; $i++) {
            [$chat, $cmd] = $chat->update($msg);
            if ($cmd === null) {
                break;
            }
            $produced = $cmd();
            $msg = $produced instanceof TickRequest ? ($produced->produce)() : $produced;
            if ($msg instanceof AssistantMsg) {
                $frames[] = [$this->runningRowIds($chat), true];
                break;
            }
            if ($msg instanceof BackendToolEventsMsg) {
                $frames[] = [$this->runningRowIds($chat), false];
            }
        }

        $walked = array_values(array_filter($frames, static fn (array $f): bool => $f[0] !== [] && !$f[1]));
        $this->assertNotEmpty($walked, 'a start row exists in an intermediate frame while the message is still streaming');
        $this->assertGreaterThan(1, count($frames), 'the cascade spans multiple fold frames, not one batched paint');

        // Terminal state equals the unpaced drain: c1 finished into its
        // result row, only the unfinished c2 placeholder stays running, and
        // the reply lands last.
        $this->assertSame(['c2'], $this->runningRowIds($chat));
        $resulted = array_values(array_filter(
            $chat->history,
            static fn (Message $m): bool => ($m->toolResults[0]->result ?? null) === 'one',
        ));
        $this->assertCount(1, $resulted, 'the finished row carries c1\'s result');

        $reply = $msg;
        $this->assertInstanceOf(AssistantMsg::class, $reply);
        [$chat] = $chat->update($reply);
        $this->assertSame('final reply', $chat->history[array_key_last($chat->history)]->content);
    }

    public function testABackendThatSettlesInlineStampsItsReplayQueue(): void
    {
        // The blocking fallback's signature: completeAsync returns an ALREADY
        // resolved promise after queueing a tool event on the way out, before
        // any loop ran. TurnRunner's probe must see the inline settle.
        $backend = new class implements Backend {
            public ?\Closure $onEvent = null;

            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                throw new \LogicException('unused');
            }

            public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                ($onEvent)(new ToolStarted('c1', 'bash', ['command' => 'ls']));

                return \React\Promise\resolve(Message::assistant('settled inline'));
            }
        };

        $msg = $this->capture($this->runStart($backend));

        $this->assertInstanceOf(BackendToolEventsMsg::class, $msg);
        $this->assertTrue($msg->replay, 'a turn that blocked the loop settles as a paced replay');
        $this->assertSame('c1', $msg->events[0]->toolCallId);
    }

    public function testABackendThatSettlesOnTheLoopIsNotStampedAsAReplay(): void
    {
        // The live/forked shape: the promise is still pending when the
        // dispatch returns, the event arrives afterwards, and the settle
        // carries a tail the pump had its chances to paint.
        $deferred = new Deferred();
        $backend = new class ($deferred) implements Backend {
            public ?\Closure $onEvent = null;

            public function __construct(private readonly Deferred $deferred) {}

            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                throw new \LogicException('unused');
            }

            public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                $this->onEvent = $onEvent;

                return $this->deferred->promise();
            }
        };

        $promise = $this->runStart($backend);
        // Both AFTER the factory returned — the dispatch is over, the loop
        // was free in between: nothing here is a replay.
        ($backend->onEvent)(new ToolStarted('c1', 'bash', ['command' => 'ls']));
        $deferred->resolve(Message::assistant('settled later'));
        $msg = $this->capture($promise);

        $this->assertInstanceOf(BackendToolEventsMsg::class, $msg);
        $this->assertFalse($msg->replay, 'a live tail keeps the ordinary Cmd-speed drain');
    }

    private function runStart(Backend $backend): PromiseInterface
    {
        $thunk = TurnRunner::new()->start($backend, [Message::user('go')], new \ArrayObject(), self::GENERATION, new CancellationToken());

        return $thunk();
    }

    /**
     * Read the Msg a settled-promise chain produces. Both shapes here are
     * fully resolved by the time this runs (inline settles resolve at the
     * dispatch; the looped one is resolved by the test itself), so the
     * callback fires on attach — no loop, no clock.
     */
    private function capture(PromiseInterface $promise): mixed
    {
        $settled = null;
        $promise->then(static function (mixed $msg) use (&$settled): void {
            $settled = $msg;
        });

        return $settled;
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\App\BackendToolEventsMsg;
use SugarCraft\Code\App\ToolEventPumpMsg;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Events\ToolFinished;
use SugarCraft\Code\Events\ToolProgress;
use SugarCraft\Code\Events\ToolStarted;
use SugarCraft\Code\Message;
use SugarCraft\Code\Tools\ToolResult;
use SugarCraft\Code\Tui\ToolTailSlots;

/**
 * C-TOOL-3.b's Chat-side contract for ToolProgress, driven through the two
 * REAL folds exactly the way MediaProgressFoldTest drives the preview slot:
 * the live {@see ToolEventPumpMsg} pump and the settle-time
 * {@see BackendToolEventsMsg} chain. Before this step both arms folded the
 * frame into recordEvent only and the tail was a dormant wire - the ring kept
 * writing, the store kept accepting, and nothing ever painted. Now each arm
 * must (a) land the frame in {@see ToolTailSlots}, (b) keep the transcript
 * byte-clean - the tail is display-only, its rows only ever ride the running
 * placeholder until the settle replaces it - and (c) obey the same
 * generation-staleness guard as every other live event. Settling the call
 * clears the slot, and an out-of-order frame can never repaint backwards.
 */
final class ToolTailFoldTest extends TestCase
{
    protected function setUp(): void
    {
        ToolTailSlots::clearAll();
    }

    protected function tearDown(): void
    {
        ToolTailSlots::clearAll();
    }

    public function testTheLivePumpWritesTheTailIntoTheDisplaySlot(): void
    {
        $inbox = new \ArrayObject([[0, self::tailFrame('call-1', "alpha\nbeta", 1)]]);
        $chat = $this->foldChat(inbox: $inbox);
        $before = count($chat->history);

        [$chat, $cmd] = $chat->update(new ToolEventPumpMsg());

        $this->assertNull($cmd, 'one entry, drained, the pump stops');
        $slot = ToolTailSlots::peek('call-1');
        $this->assertNotNull($slot, 'the frame reached the display slot - this is the wire that was dormant');
        $this->assertSame("alpha\nbeta", $slot['tail']);
        $this->assertSame(1, $slot['seq']);
        $this->assertCount($before, $chat->history, 'a tail frame is not a transcript row');
    }

    public function testTheLivePumpDiscardsAFrameFromAnotherGeneration(): void
    {
        $inbox = new \ArrayObject([[99, self::tailFrame('call-1', 'stale-write', 1)]]);
        $chat = $this->foldChat(inbox: $inbox);

        [$after, $cmd] = $chat->update(new ToolEventPumpMsg());

        $this->assertNull($cmd, 'the stale entry is consumed, the pump must not spin');
        $this->assertCount(0, $inbox);
        $this->assertNull(ToolTailSlots::peek('call-1'), 'an aborted turn has nothing to paint on this one');
    }

    public function testAnOutOfOrderFrameCannotRepaintTheRowBackwards(): void
    {
        $inbox = new \ArrayObject([
            [0, self::tailFrame('call-1', 'second-write', 2)],
            [0, self::tailFrame('call-1', 'first-write', 1)],
        ]);
        $chat = $this->foldChat(inbox: $inbox);

        [$chat, $cmd] = $chat->update(new ToolEventPumpMsg());
        $produced = $cmd();
        $this->assertInstanceOf(ToolEventPumpMsg::class, $produced);
        [$chat, $cmd] = $chat->update($produced);
        $this->assertNull($cmd);

        $this->assertSame(1, ToolTailSlots::count(), 'in flight and after: one call owns one slot');
        $this->assertSame('second-write', ToolTailSlots::peek('call-1')['tail'], 'the newest sequence wins');
        $this->assertSame(2, ToolTailSlots::peek('call-1')['seq']);
    }

    public function testTheSettledQueueFoldsTheTailIntoTheSlotToo(): void
    {
        $chat = $this->foldChat();

        $after = $this->driveChain($chat, new BackendToolEventsMsg(
            [
                new ToolStarted('call-1', 'Bash', ['command' => 'echo x']),
                self::tailFrame('call-1', 'settled-tail', 1, true),
            ],
            Message::assistant('waiting'),
            generation: null,
        ));

        $slot = ToolTailSlots::peek('call-1');
        $this->assertNotNull($slot, 'the settled queue twin writes the slot the pump arm does');
        $this->assertSame('settled-tail', $slot['tail']);
        $this->assertTrue($slot['truncated'], 'the byte-cap honesty flag rides through the fold');
        foreach ($after->history as $row) {
            $this->assertStringNotContainsString('settled-tail', $row->content, 'a queue-folded tail is paint, not a transcript row');
        }
    }

    public function testTheSettleChainClearsTheSlotWhenTheCallFinishes(): void
    {
        $chat = $this->foldChat();

        $after = $this->driveChain($chat, new BackendToolEventsMsg(
            [
                new ToolStarted('call-1', 'Bash', ['command' => 'echo x']),
                self::tailFrame('call-1', 'settled-tail', 1),
                new ToolFinished('call-1', 'Bash', new ToolResult('call-1', 'echo done')),
            ],
            Message::assistant('pair done'),
            generation: null,
        ));

        $this->assertNull(ToolTailSlots::peek('call-1'), 'settle clears the slot - no abandoned tail outlives its call');
        foreach ($after->history as $row) {
            $this->assertStringNotContainsString('settled-tail', $row->content, 'live tail bytes never reach a stored message');
        }
        $this->assertStringContainsString('pair done', $after->history[count($after->history) - 1]->content);
    }

    public function testTheFinishedHalfClearsOnlyItsOwnCallSlot(): void
    {
        ToolTailSlots::set('call-1', 'one-tail', false, 1);
        ToolTailSlots::set('call-2', 'two-tail', false, 1);
        $chat = $this->foldChat();

        $after = $this->driveChain($chat, new BackendToolEventsMsg(
            [new ToolFinished('call-1', 'Bash', new ToolResult('call-1', 'one of two settled'))],
            Message::assistant('pair done'),
            generation: null,
        ));

        $this->assertNull(ToolTailSlots::peek('call-1'));
        $this->assertNotNull(ToolTailSlots::peek('call-2'), 'the render still in flight keeps its tail');
        $this->assertStringContainsString('pair done', $after->history[count($after->history) - 1]->content);
    }

    public function testAStaleGenerationSettleChainNeverTouchesTheSlot(): void
    {
        $chat = $this->foldChat();

        [$after, $cmd] = $chat->update(new BackendToolEventsMsg(
            [self::tailFrame('call-1', 'late-write', 1)],
            Message::assistant('late'),
            generation: 99,
        ));

        $this->assertNull($cmd, 'fixture: the staleness guard breaks the chain here');
        $this->assertNull(ToolTailSlots::peek('call-1'), 'a superseded turn may not paint on the new one');
    }

    private static function tailFrame(string $callId, string $tail, int $seq, bool $truncated = false): ToolProgress
    {
        return new ToolProgress($callId, 'Bash', $tail, $seq, $truncated, 4096);
    }

    /** @param list<Message>|null $history */
    private function foldChat(?array $history = null, ?\ArrayObject $inbox = null): Chat
    {
        return new Chat(
            history: $history ?? [Message::user('hello'), Message::assistant('hi')],
            backend: new EchoBackend(),
            liveToolEvents: $inbox,
        );
    }

    private function driveChain(Chat $chat, BackendToolEventsMsg $msg): Chat
    {
        $current = $chat;
        $next = $msg;
        for ($i = 0; $i < 8 && $next !== null; $i++) {
            [$current, $cmd] = $current->update($next);
            $next = null;
            if ($cmd !== null) {
                $produced = $cmd();
                if ($produced instanceof \SugarCraft\Core\Msg) {
                    $next = $produced;
                }
            }
        }

        return $current;
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\BackendToolEventsMsg;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Events\MediaProgress;
use SugarCraft\Crush\Events\ToolFinished;
use SugarCraft\Crush\Events\ToolStarted;
use SugarCraft\Crush\Media\PreviewSlots;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\ToolEventPumpMsg;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * W2.4's Chat-side contract for MediaProgress, driven through the two REAL
 * folds exactly the way MidTurnSpendCapTranscriptTest drives the breach:
 * the live {@see ToolEventPumpMsg} pump and the settle-time
 * {@see BackendToolEventsMsg} chain. Every fold must (a) land the frame in
 * PreviewSlots, (b) write NOTHING into the transcript - preview bytes are
 * display-only and may never reach the session store (crush_media §8.6-5) -
 * and (c) obey the same generation-staleness guard as every other live event.
 * Settling the call must clear the slot.
 */
final class MediaProgressFoldTest extends TestCase
{
    protected function setUp(): void
    {
        PreviewSlots::clearAll();
    }

    protected function tearDown(): void
    {
        PreviewSlots::clearAll();
    }

    public function testTheLivePumpWritesOnlyTheSlotAndKeepsTranscriptBytesOut(): void
    {
        $inbox = new \ArrayObject([[0, new MediaProgress('call-1', 'ZnJhbWU=', 0.4, 5.0)]]);
        $chat = $this->foldChat(inbox: $inbox);
        $before = count($chat->history);

        [$chat, $cmd] = $chat->update(new ToolEventPumpMsg());

        $this->assertNull($cmd, 'one entry, drained, the pump stops');
        $slot = PreviewSlots::peek('call-1');
        $this->assertNotNull($slot, 'the frame reached the display slot');
        $this->assertSame('ZnJhbWU=', $slot['frameB64']);
        $this->assertSame(0.4, $slot['progress']);
        $this->assertCount($before, $chat->history, 'a preview frame is not a transcript row');
    }

    public function testTheLivePumpDiscardsAFrameFromAnotherGeneration(): void
    {
        $inbox = new \ArrayObject([[99, new MediaProgress('call-1', 'Zg==', 0.9, 1.0)]]);
        $chat = $this->foldChat(inbox: $inbox);

        [$after, $cmd] = $chat->update(new ToolEventPumpMsg());

        $this->assertNull($cmd, 'the stale entry is consumed, the pump must not spin');
        $this->assertCount(0, $inbox);
        $this->assertNull(PreviewSlots::peek('call-1'), 'an aborted turn has nothing to paint on this one');
    }

    public function testSuccessiveFramesReplaceTheSlotWithoutAppendingAnything(): void
    {
        $inbox = new \ArrayObject([
            [0, new MediaProgress('call-1', 'Zmlyc3Q=', 0.2, 8.0)],
            [0, new MediaProgress('call-1', 'c2Vjb25k', 0.6, 4.0)],
        ]);
        $chat = $this->foldChat(inbox: $inbox);

        [$chat, $cmd] = $chat->update(new ToolEventPumpMsg());
        $produced = $cmd();
        $this->assertInstanceOf(ToolEventPumpMsg::class, $produced);
        [$chat, $cmd] = $chat->update($produced);
        $this->assertNull($cmd);

        $this->assertSame(1, PreviewSlots::count(), 'in flight and after: one call owns one slot');
        $this->assertSame('c2Vjb25k', PreviewSlots::peek('call-1')['frameB64'], 'the newest frame wins');
    }

    public function testTheSettleChainFoldsFramesThenTheFinishedEventClearsTheSlot(): void
    {
        $chat = $this->foldChat();
        $msg = new BackendToolEventsMsg(
            [
                new ToolStarted('call-1', 'GenerateImage', ['prompt' => 'x']),
                new MediaProgress('call-1', 'ZnJhbWU=', 0.8, 1.0),
                new ToolFinished('call-1', 'GenerateImage', new ToolResult('call-1', 'saved a picture')),
            ],
            Message::assistant('done'),
            generation: null,
        );

        $after = $this->driveChain($chat, $msg);

        $this->assertNull(PreviewSlots::peek('call-1'), 'settle clears the slot - no abandoned frame outlives its call');
        $last = $after->history[count($after->history) - 1]->content;
        $this->assertStringContainsString('done', $last, 'the chain still settled the reply');
        foreach ($after->history as $row) {
            $this->assertStringNotContainsString('ZnJhbWU=', $row->content, 'preview bytes never reach a stored message');
        }
    }

    public function testAStaleGenerationSettleChainNeverTouchesTheSlot(): void
    {
        $chat = $this->foldChat();

        [$after, $cmd] = $chat->update(new BackendToolEventsMsg(
            [new MediaProgress('call-1', 'Zg==', 0.5, 2.0)],
            Message::assistant('late'),
            generation: 99,
        ));

        $this->assertNull($cmd, 'fixture: the staleness guard breaks the chain here');
        $this->assertNull(PreviewSlots::peek('call-1'), 'a superseded turn may not paint on the new one');
    }

    public function testTheFinishedHalfClearsOnlyItsOwnCallSlot(): void
    {
        PreviewSlots::set('call-1', 'QQ==', 0.5, null);
        PreviewSlots::set('call-2', 'Qg==', 0.5, null);
        $chat = $this->foldChat();

        $after = $this->driveChain($chat, new BackendToolEventsMsg(
            [new ToolFinished('call-1', 'GenerateImage', new ToolResult('call-1', 'one of two settled'))],
            Message::assistant('pair done'),
            generation: null,
        ));

        $this->assertNull(PreviewSlots::peek('call-1'));
        $this->assertNotNull(PreviewSlots::peek('call-2'), 'the render still in flight keeps its preview');
        $this->assertStringContainsString('pair done', $after->history[count($after->history) - 1]->content);
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

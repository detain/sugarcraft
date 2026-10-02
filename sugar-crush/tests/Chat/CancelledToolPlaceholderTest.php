<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\BackendToolEventsMsg;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Events\ToolFinished;
use SugarCraft\Crush\Events\ToolStarted;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\ToolCall;
use SugarCraft\Crush\ToolEventPumpMsg;
use SugarCraft\Crush\ToolResult;
use SugarCraft\Crush\ToolResultsMsg;
use SugarCraft\Crush\Tools\ToolCall as EngineToolCall;
use SugarCraft\Crush\Tools\ToolResult as EngineToolResult;

/**
 * Audit 15b-02: a double-Escape cancel used to leave every "running" tool
 * placeholder spinning in the transcript for the rest of the session, and a
 * later turn whose parser REUSES the call id (DSML and MiniMax restart at
 * `dsml_call_0` on every response) had its result land on the cancelled
 * turn's row — the replace-by-id walked history from the top — while its own
 * placeholder spun forever.
 *
 * Driven through the real entry points: Enter submits, the live inbox is
 * drained by {@see ToolEventPumpMsg} exactly as the subscription tick drains
 * it, and the cancel is two real Escape KeyMsgs.
 */
final class CancelledToolPlaceholderTest extends TestCase
{
    private const REPEATED_ID = 'dsml_call_0';

    public function testDoubleEscapeHealsRunningPlaceholdersAndALaterSameIdResultLandsOnTheNewRow(): void
    {
        $chat = $this->submit(new Chat(backend: new EchoBackend()), 'turn one');
        $chat->enqueueToolEvent(new ToolStarted(
            self::REPEATED_ID,
            'Bash',
            ['command' => 'sleep 100', 'description' => 'slow thing'],
        ));
        [$chat] = $chat->update(new ToolEventPumpMsg());
        $this->assertSame(self::REPEATED_ID, $this->last($chat)->pendingToolCallId, 'precondition: a running row');

        [$chat] = $chat->update(new KeyMsg(KeyType::Escape, ''));
        [$cancelled] = $chat->update(new KeyMsg(KeyType::Escape, ''));

        $this->assertFalse($cancelled->inFlight);
        $this->assertSame([], $this->pendingRows($cancelled), 'a cancelled turn left a placeholder spinning');
        $turnOneIndex = $this->interruptedRowIndex($cancelled, 'slow thing');
        $this->assertSame('_Request cancelled._', $this->last($cancelled)->content);

        $chat = $this->submit($cancelled, 'turn two');
        $chat->enqueueToolEvent(new ToolStarted(
            self::REPEATED_ID,
            'Read',
            ['path' => 'README.md', 'description' => 'read readme'],
        ));
        $chat->enqueueToolEvent(new ToolFinished(
            self::REPEATED_ID,
            'Read',
            new EngineToolResult(self::REPEATED_ID, 'README CONTENTS'),
        ));
        [$chat] = $chat->update(new ToolEventPumpMsg());
        [$done] = $chat->update(new ToolEventPumpMsg());

        $this->assertSame([], $this->pendingRows($done), "turn two's own placeholder was never replaced");
        $newest = $this->last($done);
        $this->assertCount(1, $newest->toolResults);
        $this->assertSame('README CONTENTS', $newest->toolResults[0]->result);
        $this->assertNull($newest->toolResults[0]->error);
        $this->assertSame(
            $turnOneIndex,
            $this->interruptedRowIndex($done, 'slow thing'),
            "turn two's result overwrote the cancelled turn's row",
        );
    }

    /**
     * The healed row is the one /rewind and resume already build, so the three
     * "this call lost its runner" paths render and serialise identically —
     * including the tool_result that keeps the next request's wire valid. The
     * one difference is the reason (audit R15): nothing restarted, the user
     * cancelled, and the model reads that text on the next request.
     */
    public function testHealedRowMatchesTheCheckpointRevivalShape(): void
    {
        $chat = $this->submit(new Chat(backend: new EchoBackend()), 'go');
        $call = new EngineToolCall('call_7', 'bash', ['command' => 'ls', 'description' => 'list files']);
        $chat->enqueueToolEvent(ToolStarted::fromCall($call));
        [$chat] = $chat->update(new ToolEventPumpMsg());
        $placeholder = $this->last($chat);

        [$chat] = $chat->update(new KeyMsg(KeyType::Escape, ''));
        [$cancelled] = $chat->update(new KeyMsg(KeyType::Escape, ''));

        $healed = $cancelled->history[count($cancelled->history) - 2];
        $revived = Chat::reviveCheckpointMessage($placeholder->jsonSerialize());
        $this->assertCount(1, $healed->toolResults);
        $this->assertSame($revived->toolResults[0]->name, $healed->toolResults[0]->name, 'same row, named the same way');
        $this->assertSame($revived->toolResults[0]->id, $healed->toolResults[0]->id);
        $this->assertSame($revived->role, $healed->role);
        $this->assertTrue($healed->toolResults[0]->isError());
        $this->assertSame('call_7', $healed->toolResults[0]->id);
        $this->assertTrue(Chat::isInterruptedResult($healed->toolResults[0]), 'drawn as the same ⊘ interrupted state');
    }

    /**
     * Audit R15 (15b-02 residual): the cancel heal reused the restart text, so
     * a session that never restarted told the user — and, on the next request,
     * the model — that the call was "interrupted by restart".
     */
    public function testACancelHealSaysTheUserCancelledNotThatTheProcessRestarted(): void
    {
        $chat = $this->submit(new Chat(backend: new EchoBackend()), 'go');
        $call = new EngineToolCall('call_8', 'bash', ['command' => 'sleep 9', 'description' => 'wait']);
        $chat->enqueueToolEvent(ToolStarted::fromCall($call));
        [$chat] = $chat->update(new ToolEventPumpMsg());

        [$chat] = $chat->update(new KeyMsg(KeyType::Escape, ''));
        [$cancelled] = $chat->update(new KeyMsg(KeyType::Escape, ''));

        $healed = $cancelled->history[count($cancelled->history) - 2];
        $this->assertSame(Chat::CANCELLED_TOOL_CALL, $healed->content);
        $this->assertSame(Chat::CANCELLED_TOOL_CALL, $healed->toolResults[0]->error);
        $this->assertStringNotContainsString('restart', $healed->content);
        $this->assertStringNotContainsString('restart', (string) $healed->toolResults[0]->error);

        // A real restart still says restart: the checkpoint revival is unchanged.
        $revived = Chat::reviveCheckpointMessage(['role' => 'system', 'content' => 'wait', 'pendingToolCallId' => 'call_9']);
        $this->assertSame(Chat::INTERRUPTED_TOOL_CALL, $revived->toolResults[0]->error);
    }

    /**
     * Newest-first independently of the cancel heal: whatever else leaves a
     * stale same-id placeholder above the live one, the engine path's
     * {@see ToolFinished} must resolve the live one.
     */
    public function testEngineToolFinishedReplacesTheNewestSameIdPlaceholder(): void
    {
        $stale = Message::toolRunning(new ToolCall('bash', ['description' => 'old call'], self::REPEATED_ID));
        $live = Message::toolRunning(new ToolCall('bash', ['description' => 'new call'], self::REPEATED_ID));
        $chat = new Chat(history: [$stale, Message::user('again'), $live], generation: 4);

        $finished = new ToolFinished(
            self::REPEATED_ID,
            'bash',
            new EngineToolResult(self::REPEATED_ID, 'fresh output'),
        );
        [$next] = $chat->update(new BackendToolEventsMsg([$finished], Message::assistant('done'), 4));

        $this->assertSame(self::REPEATED_ID, $next->history[0]->pendingToolCallId, 'the stale row was consumed');
        $this->assertNull($next->history[2]->pendingToolCallId);
        $this->assertSame('fresh output', $next->history[2]->toolResults[0]->result);
    }

    /**
     * The Chat-native pipeline's twin: {@see ToolResultsMsg} used to replace
     * EVERY placeholder carrying the id, so one result was written onto rows
     * from two different turns.
     */
    public function testChatNativeToolResultsReplaceOnlyTheNewestSameIdPlaceholder(): void
    {
        $stale = Message::toolRunning(new ToolCall('bash', ['description' => 'old call'], self::REPEATED_ID));
        $live = Message::toolRunning(new ToolCall('bash', ['description' => 'new call'], self::REPEATED_ID));
        $chat = new Chat(
            backend: new EchoBackend(),
            history: [$stale, Message::user('again'), $live],
            generation: 4,
        );

        [$next] = $chat->update(new ToolResultsMsg(
            Message::assistant(''),
            [ToolResult::ok('bash', 'fresh output', self::REPEATED_ID)],
            4,
        ));

        $this->assertSame(self::REPEATED_ID, $next->history[0]->pendingToolCallId, 'the stale row was consumed');
        $this->assertNull($next->history[2]->pendingToolCallId);
        $this->assertSame('fresh output', $next->history[2]->toolResults[0]->result);
    }

    private function submit(Chat $chat, string $text): Chat
    {
        foreach (mb_str_split($text) as $char) {
            [$chat] = $chat->update(new KeyMsg(KeyType::Char, $char));
        }
        [$chat] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertTrue($chat->inFlight, 'precondition: the turn is running');

        return $chat;
    }

    private function last(Chat $chat): Message
    {
        return $chat->history[count($chat->history) - 1];
    }

    /** @return list<Message> */
    private function pendingRows(Chat $chat): array
    {
        return array_values(array_filter(
            $chat->history,
            static fn(Message $m): bool => $m->pendingToolCallId !== null,
        ));
    }

    private function interruptedRowIndex(Chat $chat, string $description): int
    {
        $found = [];
        foreach ($chat->history as $i => $message) {
            foreach ($message->toolResults as $result) {
                if (Chat::isInterruptedResult($result) && str_contains($result->name, $description)) {
                    $found[] = $i;
                }
            }
        }
        $this->assertCount(1, $found, "expected exactly one interrupted row for '{$description}'");

        return $found[0];
    }
}

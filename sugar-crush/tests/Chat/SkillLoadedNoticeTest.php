<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Events\ToolFinished;
use SugarCraft\Crush\Events\ToolStarted;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Permissions\DenialKind;
use SugarCraft\Crush\ToolCall;
use SugarCraft\Crush\ToolEventPumpMsg;
use SugarCraft\Crush\ToolResult;
use SugarCraft\Crush\ToolResultsMsg;
use SugarCraft\Crush\Tools\ToolResult as EngineToolResult;

/**
 * Lane B (skills-qa F3): when a Skill call finishes successfully the transcript
 * gains a compact "✨ Loaded skill: <name>" row right after it - the visible
 * affordance that loading a skill actually happened, beside the row that now
 * names the skill too. Refusals, interrupts and errors print nothing extra:
 * their own rows already say what happened.
 *
 * Both settle paths are driven through their real entry points, the way
 * {@see CancelledToolPlaceholderTest} drives them: the Chat-native batch via a
 * {@see ToolResultsMsg}, the engine pipeline via enqueued tool events drained
 * by {@see ToolEventPumpMsg}.
 */
final class SkillLoadedNoticeTest extends TestCase
{
    public function testABatchedSkillResultLandsItsNoticeAfterTheRow(): void
    {
        $chat = new Chat(
            history: [Message::toolRunning(new ToolCall('Skill', ['name' => 'review'], 'c1'))],
            backend: new EchoBackend(),
        );
        [$next] = $chat->update(new ToolResultsMsg(
            Message::assistant(''),
            [new ToolResult('Skill', 'the body', id: 'c1')],
        ));

        $index = $this->resultIndex($next, 'c1');
        $this->assertNotNull($index, 'the batch never replaced its placeholder');
        $notice = $next->history[$index + 1];
        $this->assertSame('✨ Loaded skill: review', $notice->content);
        $this->assertTrue($notice->uiOnly, 'the notice row must never reach the model');
    }

    public function testALiveEngineSkillResultLandsItsNoticeAfterTheRow(): void
    {
        $chat = $this->submit(new Chat(backend: new EchoBackend()), 'use the skill');
        $chat->enqueueToolEvent(new ToolStarted('c2', 'Skill', ['name' => 'security-audit']));
        [$chat] = $chat->update(new ToolEventPumpMsg());
        $this->assertSame('c2', $this->last($chat)->pendingToolCallId, 'precondition: a running row');

        $chat->enqueueToolEvent(new ToolFinished('c2', 'Skill', new EngineToolResult('c2', 'the body')));
        [$done] = $chat->update(new ToolEventPumpMsg());

        $index = $this->resultIndex($done, 'c2');
        $this->assertNotNull($index, "the engine result never replaced its placeholder");
        $this->assertSame('✨ Loaded skill: security-audit', $done->history[$index + 1]->content);
    }

    public function testAnErrorSkillResultPrintsNoNotice(): void
    {
        $chat = new Chat(
            history: [Message::toolRunning(new ToolCall('Skill', ['name' => 'gone'], 'c3'))],
            backend: new EchoBackend(),
        );
        [$next] = $chat->update(new ToolResultsMsg(
            Message::assistant(''),
            [new ToolResult('Skill', '', error: 'no such skill: gone', id: 'c3')],
        ));

        $this->assertSame($this->historyWithoutNotice($next), $next->history, 'a failed load announced itself anyway');
    }

    public function testACancelledOrNamelessResultPrintsNoNotice(): void
    {
        foreach ([
            new ToolResult('Skill', '', error: Chat::CANCELLED_TOOL_CALL, id: 'c4'),
            new ToolResult('Skill', 'body', id: 'c5'),
            new ToolResult('Read', 'body', id: 'c6', arguments: ['name' => 'review']),
        ] as $result) {
            $chat = new Chat(
                // The settle path replays the PLACEHOLDER's arguments onto the
                // result, so the fixture's placeholder must carry exactly the
                // arguments this case is about.
                history: [Message::toolRunning(new ToolCall($result->name, $result->arguments, $result->id ?? 'x'))],
                backend: new EchoBackend(),
            );
            [$next] = $chat->update(new ToolResultsMsg(Message::assistant(''), [$result]));

            $this->assertSame(
                $this->historyWithoutNotice($next),
                $next->history,
                "a {$result->name} row (id {$result->id}) grew a notice it should not have",
            );
        }
    }

    public function testADeniedSkillResultPrintsNoNotice(): void
    {
        // The isDeniedResult leg, pinned apart from the error leg above: the
        // structural denial (audit F-P8) rides independently of the text, and
        // the rehydration paths (Message::fromArray, Chat's checkpoint read)
        // rebuild `denial` from the stored string on their own — so a row that
        // carries a refusal kind without an error string is a live shape, not
        // a contradiction. Review r-review lane B, MINOR-2.
        $chat = new Chat(
            history: [Message::toolRunning(new ToolCall('Skill', ['name' => 'review'], 'c7'))],
            backend: new EchoBackend(),
        );
        [$next] = $chat->update(new ToolResultsMsg(Message::assistant(''), [
            new ToolResult('Skill', 'body', null, 'c7', arguments: ['name' => 'review'], denial: DenialKind::Refused),
        ]));

        $this->assertNotNull($this->resultIndex($next, 'c7'), 'precondition: the refused row settled');
        $this->assertSame(
            $this->historyWithoutNotice($next),
            $next->history,
            'a refused skill load announced itself anyway',
        );
    }

    /** The batch path's own replacement, minus anything matching the notice shape. */
    private function historyWithoutNotice(Chat $chat): array
    {
        return array_values(array_filter(
            $chat->history,
            static fn (Message $m): bool => !str_starts_with($m->content, '✨ Loaded skill: '),
        ));
    }

    private function resultIndex(Chat $chat, string $id): ?int
    {
        foreach ($chat->history as $i => $message) {
            foreach ($message->toolResults as $result) {
                if ($result->id === $id) {
                    return $i;
                }
            }
        }

        return null;
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
}

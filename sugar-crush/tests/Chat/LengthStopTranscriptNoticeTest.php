<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\AssistantMsg;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Role;

/**
 * E707 (round 81), the notice half: the acceptance line is that a reply the
 * provider stopped at its OUTPUT ceiling reaches the transcript instead of
 * passing as a finished thought. That is the LAST hop of the chain
 * (wire parse -> Runtime fold -> EngineBackend root Message -> Chat settle
 * arm), and this file pins it end to end the way the settle arm actually runs:
 * a real {@see AssistantMsg} applied to a real {@see Chat}, with the flag
 * arriving on the Message exactly as EngineBackend::complete() delivers it.
 *
 * The clean-turn arm matters as much as the stopped one: the notice fires on
 * the flag and on NOTHING else, so the ordinary history of an ordinary reply
 * stays byte-identical.
 */
final class LengthStopTranscriptNoticeTest extends TestCase
{
    /** The exact sentence Chat writes — pinned whole because the transcript is the product. */
    private const NOTICE = 'The provider stopped this reply at its output limit, so the text above may end mid-thought. '
        . 'Raise "maxOutputTokens" in ~/.sugar-crush/config.json for a longer single reply, '
        . 'or ask for the remainder in your next message.';

    public function testASettledCeilingStoppedReplyIsFollowedByOneSystemNotice(): void
    {
        [$chat] = $this->settle(Message::assistant('the text stops')->withLengthStopped(true));

        $history = $chat->history;
        $last = $history[array_key_last($history)];
        $secondToLast = $history[array_key_last($history) - 1];

        $this->assertSame('the text stops', $secondToLast->content, 'the partial reply settles first — it is real (billed) content');
        $this->assertSame(Role::System, $last->role, 'the notice is a system line, the house shape every other post-turn advisory takes');
        $this->assertSame(self::NOTICE, $last->content, 'the sentence names the cause, the uncertainty, the knob, and the fallback ask — all four in one line');
        $this->assertStringNotContainsString("\x1b", $last->content, 'transcript text carries no raw ANSI');
    }

    public function testACleanSettledReplyChangesNothing(): void
    {
        [$stopped] = $this->settle(Message::assistant('all of it'));

        $contents = array_map(static fn (Message $m): string => $m->content, $stopped->history);

        $this->assertNotContains(self::NOTICE, $contents, 'no flag, no notice — the default false is what keeps ordinary turns ordinary');
        $this->assertSame('all of it', $contents[array_key_last($contents)]);
        $this->assertCount(2, $stopped->history, 'user, assistant, done: the settle of a clean turn is byte-identical to the pre-E707 shape');
    }

    public function testTheNoticeLandsAfterTheReplyAndBeforeTheNextTurn(): void
    {
        [$chat] = $this->settle(Message::assistant('cut short')->withLengthStopped(true));

        // One notice per stopped turn, positioned between the turn it
        // describes and whatever comes next — a user reading the transcript
        // top-down learns "that answer was cut" before they type again.
        $noticeRows = array_values(array_filter(
            $chat->history,
            static fn (Message $m): bool => $m->role === Role::System && $m->content === self::NOTICE,
        ));

        $roles = array_map(static fn (Message $m): Role => $m->role, $chat->history);

        $this->assertSame([Role::User, Role::Assistant, Role::System], $roles);
        $this->assertCount(1, $noticeRows, 'exactly one notice per stopped turn — the settle arm must not stamp it twice');
    }

    /** @return array{0: Chat, 1: mixed} */
    private function settle(Message $reply): array
    {
        $chat = new Chat(
            history: [Message::user('tell me a long story')],
            backend: new EchoBackend(),
        );

        return $chat->update(new AssistantMsg($reply));
    }
}

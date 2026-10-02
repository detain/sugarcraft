<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\AssistantMsg;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\Usage;

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

    /**
     * F2's sibling sentence, pinned whole for the same reason. It no longer
     * says "tool results still pending": since the budget exit ends in a
     * no-tools summary request, the reply above is that summary.
     */
    private const STEPS_NOTICE = 'This turn used all of its tool steps before the work was done, so the reply above describes where it '
        . 'stopped — what is finished and what remains — not a finished answer. Say "continue" to pick it up '
        . 'from there, or raise "maxToolSteps" in ~/.sugar-crush/config.json for longer agentic turns.';

    /** The loop guard's own sentence (the third harness stop), with the tool it ended. */
    private const LOOP_NOTICE = 'This turn was ended by the repeat-call loop guard: the model called %s 8 times with the same arguments '
        . 'and got the same result each time. The reply above describes where it stopped. Point it at a different '
        . 'approach, or say "continue" once something has changed.';

    /** The billing wave's third sibling, spelled with the offending model name. */
    private const UNPRICED_NOTICE = 'This app has no price on file for model "%s", so this turn billed $0.00 '
        . 'as a lower bound, not as a free call — spend totals and the spend cap are under-counted '
        . 'until a rate exists. Declare one under "modelPrices" in ~/.sugar-crush/config.json '
        . '(USD per 1M tokens, keys "input" and "output") to price it.';

    public function testASettledStepTruncatedTurnIsFollowedByOneSystemNotice(): void
    {
        [$chat] = $this->settle(Message::assistant('half a loop')->withStepsTruncated(true));

        $history = $chat->history;
        $last = $history[array_key_last($history)];

        $this->assertSame(Role::System, $last->role);
        $this->assertSame(self::STEPS_NOTICE, $last->content, 'F2: the silently-exhausted loop becomes one loud transcript line naming its own knob');
        $this->assertStringNotContainsString("\x1b", $last->content);
    }

    public function testTheStepNoticeNoLongerClaimsToolResultsArePending(): void
    {
        [$chat] = $this->settle(Message::assistant('done: a; remaining: b')->withStepsTruncated(true));
        $last = $chat->history[array_key_last($chat->history)];

        $this->assertStringNotContainsString('still pending', $last->content, 'the summary request settled every step; nothing is pending');
        $this->assertStringContainsString('maxToolSteps', $last->content, 'it still names the knob');
    }

    public function testATurnTheLoopGuardEndedIsFollowedByItsOwnNotice(): void
    {
        [$chat] = $this->settle(Message::assistant('I kept repeating probe')->withLoopGuardStoppedBy('probe'));

        $history = $chat->history;
        $last = $history[array_key_last($history)];

        $this->assertSame(Role::System, $last->role);
        $this->assertSame(sprintf(self::LOOP_NOTICE, 'probe'), $last->content);
        $this->assertStringNotContainsString('maxToolSteps', $last->content, 'raising the step budget is the wrong remedy for a loop');
        $this->assertSame('I kept repeating probe', $history[array_key_last($history) - 1]->content, 'the summary settles first');
    }

    public function testTheLoopNoticeQuotesTheModelChosenToolNameSafely(): void
    {
        [$chat] = $this->settle(Message::assistant('x')->withLoopGuardStoppedBy("evil\x1b]0;pwned\x07\nname"));
        $last = $chat->history[array_key_last($chat->history)];

        $this->assertStringContainsString('repeat-call loop guard', $last->content, 'fixture: the notice really fired');
        $this->assertStringNotContainsString("\x1b", $last->content);
        $this->assertStringNotContainsString("\n", $last->content, 'one notice line, whatever the name carried');
    }

    public function testBothCeilingsFireTheirOwnNoticesInOrder(): void
    {
        // Provider-side verdict first (it explains the LAST reply's shape),
        // harness-side second — two different loops stopped, two sentences.
        [$chat] = $this->settle(Message::assistant('cut twice')->withLengthStopped(true)->withStepsTruncated(true));

        $roles = array_map(static fn (Message $m): Role => $m->role, $chat->history);
        $contents = array_map(static fn (Message $m): string => $m->content, $chat->history);

        $this->assertSame([Role::User, Role::Assistant, Role::System, Role::System], $roles);
        $this->assertSame(self::NOTICE, $contents[2], 'the E707 sentence keeps its position ahead of the F2 sibling');
        $this->assertSame(self::STEPS_NOTICE, $contents[3]);
    }

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

    public function testASettledUnpricedTurnIsFollowedByOneSystemNotice(): void
    {
        // Billing wave (r90 review pin): the notice hop the builder proved by
        // probe but left unpinned — an unpriceable model gets ONE loud
        // transcript line, at most once per settled turn, never per token.
        [$chat] = $this->settle(Message::assistant('cut short')->withUsage(
            Usage::new(totalTokens: 100, costUsd: 0.0, inputTokens: 40, outputTokens: 60, unpricedModel: 'gpt-6-nova'),
        ));

        $roles = array_map(static fn (Message $m): Role => $m->role, $chat->history);
        $noticeRows = array_values(array_filter(
            $chat->history,
            static fn (Message $m): bool => $m->role === Role::System
                && $m->content === sprintf(self::UNPRICED_NOTICE, 'gpt-6-nova'),
        ));

        $this->assertSame([Role::User, Role::Assistant, Role::System], $roles);
        $this->assertCount(1, $noticeRows, 'the unpriced notice lands exactly once per settled turn — it is a settle-arm line, not a per-token stream artifact');
        $this->assertStringContainsString('gpt-6-nova', $chat->history[array_key_last($chat->history)]->content, 'the notice names the model the operator has to price');
    }

    public function testAPricedSettledTurnAddsNoUnpricedNotice(): void
    {
        // The carrier polarity: a real free-or-priced 0.0 with NO name must
        // not raise the signal — that is the whole difference between
        // "measured as free" and "cannot be measured" (Usage docblock).
        [$chat] = $this->settle(Message::assistant('done')->withUsage(
            Usage::new(totalTokens: 10, costUsd: 0.0),
        ));

        $contents = array_map(static fn (Message $m): string => $m->content, $chat->history);

        $this->assertSame(['tell me a long story', 'done'], $contents);
        $this->assertCount(2, $chat->history, 'a priced (or honestly free) settle stays byte-identical to the pre-notice shape');
    }

    public function testTheBudgetReadoutDisclosesTheLowerBoundAfterAnUnpricedTurn(): void
    {
        // The disclosure half of the same seam: once a turn arrives unpriced,
        // /budget's dollar figure is a bound, not a bill, and says so —
        // naming the ONE remedy (the user-tier modelPrices map) in the line.
        [$chat] = $this->settle(Message::assistant('x')->withUsage(
            Usage::new(totalTokens: 100, costUsd: 0.0, unpricedModel: 'gpt-6-nova'),
        ));

        $budget = new \ReflectionMethod(Chat::class, 'budgetStatusLine');
        $budget->setAccessible(true);
        $line = (string) $budget->invoke($chat);

        $this->assertStringContainsString('LOWER BOUND', $line);
        $this->assertStringContainsString('modelPrices', $line, 'the disclosure names the knob, the same pointer discipline as every ceiling notice');
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

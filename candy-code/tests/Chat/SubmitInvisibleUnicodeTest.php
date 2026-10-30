<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Message;
use SugarCraft\Code\Role;

/**
 * C-SEC-8.d: Enter neutralises invisible Unicode at the TUI submit choke.
 *
 * Before this step the invisible bidi and zero-width format characters of
 * audit 15b-28 were only MARKED, on the display path; the raw bytes reached
 * the model, the transcript's saved row, and every mid-turn classifier.
 * A pasted `pay‍pal` (ZWJ between ASCII — a spoof) was billed as-is,
 * and a trailing right-to-left override could steer whatever renderer trust
 * the row later received. Now `Chat::submit()` runs the strip twin of the
 * display rule (`Sanitize::stripInvisibleFormatting` — the SAME joiner-tier
 * maps, so the two paths can never disagree) over the expanded draft BEFORE
 * the funnel classifies it, posts exactly one notice carrying the count, and
 * every route the draft takes after that — dispatch, queue, steer — carries
 * the cleaned bytes.
 *
 * The joiner tier is why there is no blanket removal: the family emoji and
 * the Persian connective below are made of the same codepoints as the spoofs
 * and must survive byte-for-byte; only a joiner that CANNOT be doing its job
 * (at the start, after ASCII, after another mark) is removed. The design
 * deliberately ships no opt-out key (plan §6, C-SEC-8): a user who means the
 * bytes attaches the text as a file, which this never touches.
 */
final class SubmitInvisibleUnicodeTest extends TestCase
{
    /** U+1F469 + U+200D + U+1F4BB — the woman-technologist family emoji. */
    private const ZWJ_FAMILY = "\u{1F469}\u{200D}\u{1F4BB}";

    /** U+0646 U+0645 U+200C U+0627 — a Persian word whose spelling is a ZWNJ. */
    private const ZWNJ_PERSIAN = "\u{0646}\u{0645}\u{200C}\u{0627}";

    private function pressEnterOn(string $draft): Chat
    {
        [$chat] = (new Chat(inputBuf: $draft, backend: new EchoBackend()))
            ->update(new KeyMsg(KeyType::Enter, ''));

        return $chat;
    }

    /** Copy of `InFlightInputQueueTest::withDraft()` byte-for-byte: the drift
     *  guard licenses exact twins and accuses near-misses (lane-fj lesson). */
    private function withDraft(Chat $chat, string $draft): Chat
    {
        return (new \ReflectionMethod(Chat::class, 'mutate'))->invoke($chat, ['inputBuf' => $draft]);
    }

    /** @return list<Message> the Role::System rows in $chat's transcript */
    private function noticeRowsOf(Chat $chat): array
    {
        return array_values(array_filter(
            $chat->history,
            static fn (Message $row): bool => $row->role === Role::System,
        ));
    }

    /** @return list<Message> the rows that are not notices or assistant echoes */
    private function userRowsOf(Chat $chat): array
    {
        return array_values(array_filter(
            $chat->history,
            static fn (Message $row): bool => $row->role === Role::User,
        ));
    }

    private function expectNoRemoval(Chat $chat, string $draft): void
    {
        $rows = $this->userRowsOf($chat);
        self::assertNotSame([], $rows, 'fixture: the draft must have dispatched a user row');
        self::assertSame($draft, $rows[0]->content, 'the user row is byte-identical to the draft');
        self::assertSame([], $this->noticeRowsOf($chat), 'nothing was removed, so no notice may ride');
    }

    public function testABidiOverrideIsStrippedAndTheCountIsNoticed(): void
    {
        // U+202E mid-sentence and a U+200B before " out": both always-tier,
        // removed unconditionally, and the user sees the bytes that are gone.
        $dirty = "check \u{202E}this\u{200B} out";
        $chat = $this->pressEnterOn($dirty);

        $rows = $this->userRowsOf($chat);
        self::assertSame('check this out', $rows[0]->content, 'both format characters are gone from the sent text');

        $notices = $this->noticeRowsOf($chat);
        self::assertCount(1, $notices, 'exactly one notice, not one per character');
        self::assertSame(
            Lang::t('chat.notice.invisible_stripped', ['count' => 2]),
            $notices[0]->content,
        );
        self::assertStringContainsString('2', $notices[0]->content, 'the notice names the count');
    }

    public function testAPlainPromptIsSentByteIdenticalWithNoNotice(): void
    {
        $this->expectNoRemoval($this->pressEnterOn('plain ascii draft, nothing hidden'), 'plain ascii draft, nothing hidden');
    }

    public function testAZeroWidthJoinerEmojiSequenceSurvivesTheChoke(): void
    {
        // The family emoji's ZWJ sits right after a non-ASCII character —
        // the joiner tier keeps it, exactly as the display marker does.
        $draft = self::ZWJ_FAMILY . ' looks great';
        $this->expectNoRemoval($this->pressEnterOn($draft), $draft);
    }

    public function testAPersianZwnjSpellingSurvivesTheChoke(): void
    {
        $draft = self::ZWNJ_PERSIAN . ' is spelling, not spoofing';
        $this->expectNoRemoval($this->pressEnterOn($draft), $draft);
    }

    public function testASpoofingJoinerGoesAndSaysSo(): void
    {
        // The 15b-28 spoof: a ZWJ between ASCII letters. The joiner tier
        // removes it (an ASCII byte precedes) while the two tests above keep
        // their joiners — one rule, decided by position, at both tiers.
        $chat = $this->pressEnterOn("why is paypal\u{200D} down");

        $rows = $this->userRowsOf($chat);
        self::assertSame('why is paypal down', $rows[0]->content);
        $notices = $this->noticeRowsOf($chat);
        self::assertCount(1, $notices);
        self::assertSame(Lang::t('chat.notice.invisible_stripped', ['count' => 1]), $notices[0]->content);
    }

    public function testADraftThatIsOnlyInvisibleFormattingIsConsumedWithTheNotice(): void
    {
        // There is no prompt left to send: Enter must not dispatch an empty
        // turn and must not sit deaf. The draft is consumed; the notice is
        // the whole visible answer.
        $chat = $this->pressEnterOn("\u{200B}\u{FEFF}");

        self::assertSame([], $this->userRowsOf($chat), 'an all-invisible draft is not sent');
        self::assertFalse($chat->inFlight, 'and it starts no turn');
        $notices = $this->noticeRowsOf($chat);
        self::assertCount(1, $notices);
        self::assertSame(Lang::t('chat.notice.invisible_stripped', ['count' => 2]), $notices[0]->content);
    }

    public function testTheMidTurnQueueCarriesTheStrippedBytesToo(): void
    {
        // The classification runs over the CLEANED text and the QUEUE holds
        // the cleaned text: an invisible inside a queued draft cannot ride
        // into the turn that later dispatches it verbatim.
        [$chat] = (new Chat(inputBuf: 'the first thing', backend: new EchoBackend()))
            ->update(new KeyMsg(KeyType::Enter, ''));
        self::assertTrue($chat->inFlight, 'fixture: a turn must actually be in flight');

        [$done] = $this->withDraft($chat, "and also\u{200B} this")
            ->update(new KeyMsg(KeyType::Enter, ''));

        self::assertSame(['and also this'], $done->queuedPrompts(), 'the queued prompt is the stripped text');
        $needle = Lang::t('chat.notice.invisible_stripped', ['count' => 1]);
        self::assertSame(
            1,
            count(array_filter(
                $done->history,
                static fn (Message $row): bool => $row->role === Role::System && $row->content === $needle,
            )),
            'the queue route noticed the removal too, once, beside the queue notice it already carried',
        );
    }
}

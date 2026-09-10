<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\InputReader;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Chat;

/**
 * `ESC b` / `ESC f` — the ESC-prefix spelling of word motion (E2).
 *
 * readline moves by word with Alt+B/Alt+F, and terminals that encode Alt as a
 * bare ESC prefix send those as the two-byte chords `\x1bb` and `\x1bf`, which
 * candy-core's decoder answers with KeyMsg(Char 'b'|'f', alt). Until E2 those
 * messages fell through Chat's delegation and TYPED a stray letter; they now
 * route to the same offset helpers the Alt/Ctrl arrows use — one boundary
 * rule, two byte spellings.
 *
 * Everything is driven through `Chat::update()` with real decoded `KeyMsg`s,
 * and the chord fixtures assert the decoder answer itself rather than taking
 * it on trust: an arm keyed on `alt` is only reachable if `parse("\x1bb")`
 * still yields what E2's measurement recorded.
 *
 * @see ChatInputCursorTest::testAltArrowsMoveByWordWithoutEditingTheDraft()
 *      for the CSI-spelling half of the same motion
 */
final class EscWordMotionTest extends TestCase
{
    /** Feed a list of KeyMsgs through update(), returning the settled Chat. */
    private function drive(Chat $chat, KeyMsg ...$keys): Chat
    {
        foreach ($keys as $key) {
            [$chat] = $chat->update($key);
        }

        return $chat;
    }

    /** @param 'b'|'f' $rune */
    private function esc(string $rune): KeyMsg
    {
        return new KeyMsg(KeyType::Char, $rune, alt: true);
    }

    /**
     * The chord fixture: `\x1bb`/`\x1bf` really decode to the alt-flagged
     * letters E2's arm claims, and nothing else (one message, no stray
     * Escape alongside — that two-message shape is `ESC ESC[D`'s, which the
     * arm does NOT claim and the residual comment records).
     */
    public function testTheEscPrefixSpellingsDecodeToAltFlaggedLetters(): void
    {
        $reader = new InputReader();

        foreach (['b', 'f'] as $rune) {
            $decoded = $reader->parse("\x1b" . $rune);
            $this->assertCount(1, $decoded, "ESC {$rune}: one message per chord");
            $this->assertInstanceOf(KeyMsg::class, $decoded[0]);
            $this->assertSame(KeyType::Char, $decoded[0]->type);
            $this->assertSame($rune, $decoded[0]->rune);
            $this->assertTrue($decoded[0]->alt, "ESC {$rune}: decoder must set the alt flag");
            $this->assertFalse($decoded[0]->ctrl, "ESC {$rune}: alt, not ctrl");
        }
    }

    /**
     * One concrete landing per direction, so a helper pair that drifted into
     * agreement on the SAME wrong offset could not pass on equality alone.
     */
    public function testEscBFindsTheConcreteWordBoundaryMidDraft(): void
    {
        $atEnd = $this->drive(new Chat(inputBuf: 'alpha beta gamma'), new KeyMsg(KeyType::End));
        $this->assertSame(16, $atEnd->inputCursorOffset(), 'fixture: cursor at the end');

        $back = $this->drive($atEnd, $this->esc('b'));
        $this->assertSame(11, $back->inputCursorOffset(), 'ESC b lands at the start of "gamma"');
        $this->assertSame('alpha beta gamma', $back->inputBuf, 'word motion must never edit');

        $atStart = $this->drive(new Chat(inputBuf: 'alpha beta gamma'), new KeyMsg(KeyType::Home));
        $forward = $this->drive($atStart, $this->esc('f'));
        // 5 is the space in front of "beta" - wordRightOffset() is
        // wordLeftOffset()'s mirror (both dropLastWord() shapes), and that
        // boundary is the arrows' pinned behaviour, not this arm's choice.
        $this->assertSame(5, $forward->inputCursorOffset(), 'ESC f lands one word forward, exactly where Alt+Right lands');
        $this->assertSame('alpha beta gamma', $forward->inputBuf, 'word motion must never edit');
    }

    /**
     * ESC b/f land exactly where the Alt+arrow arms land, from the same draft
     * at the same offset — the two spellings must not grow two notions of a
     * word. Equality is the only thing pinned here (each concrete value is
     * pinned above), so every row's expectation is computed from the two
     * mechanisms under test and from nothing else.
     */
    public function testEscMotionAndAltArrowMotionAgreeOnEveryWordBoundary(): void
    {
        foreach ([
            'plain words' => 'alpha beta gamma',
            'multi-space gap' => 'alpha beta   gamma',
            'punctuation' => 'alpha beta, gamma;',
            'trailing space' => 'alpha beta gamma ',
            'multi-byte word' => 'alpha βῆτα γάμμα',
            'two lines' => "alpha beta\ngamma delta",
        ] as $label => $draft) {
            $fromEndBack = $this->drive(new Chat(inputBuf: $draft), new KeyMsg(KeyType::Left, alt: true));
            $escBack = $this->drive(new Chat(inputBuf: $draft), $this->esc('b'));
            $this->assertSame(
                $fromEndBack->inputCursorOffset(),
                $escBack->inputCursorOffset(),
                "{$label}: ESC b must match Alt+Left",
            );

            $fromStartFwd = $this->drive(
                new Chat(inputBuf: $draft),
                new KeyMsg(KeyType::Home),
                new KeyMsg(KeyType::Right, alt: true),
            );
            $escFwd = $this->drive(new Chat(inputBuf: $draft), new KeyMsg(KeyType::Home), $this->esc('f'));
            $this->assertSame(
                $fromStartFwd->inputCursorOffset(),
                $escFwd->inputCursorOffset(),
                "{$label}: ESC f must match Alt+Right",
            );
        }
    }

    /**
     * The arm is exactly the two runes. Ctrl+B/Ctrl+F keep typing their
     * letter through the ctrl-Char arm (the `!$msg->ctrl` guard), and any
     * other alt-flagged letter keeps reaching the widget unharmed — E2 bound
     * readline's two motions, it did not open an alt-letter regime.
     */
    public function testOnlyBAndFAreRoutedAndNothingElseChanges(): void
    {
        $ctrlB = $this->drive(new Chat(inputBuf: 'ab'), new KeyMsg(KeyType::Char, 'b', ctrl: true));
        $this->assertSame('abb', $ctrlB->inputBuf, 'Ctrl+b still types the letter');
        $this->assertSame(3, $ctrlB->inputCursorOffset());

        $altX = $this->drive(new Chat(inputBuf: 'ab'), $this->esc('x'));
        $this->assertSame('abx', $altX->inputBuf, 'another alt-flagged rune still types, as before');
        $this->assertSame(3, $altX->inputCursorOffset());
    }
}

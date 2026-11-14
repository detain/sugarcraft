<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests\Mode;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Readline\Mode\EmacsMode;
use SugarCraft\Readline\TextPrompt;
use SugarCraft\Readline\UndoManager;

/**
 * S-20 (finding 017): Emacs-mode word kills and motions go through
 * TextPrompt's atomic deleteRange()/withCursor() instead of replaying
 * Home/Right/Delete keystrokes. Terminal states are byte-identical to the
 * old replay chains (transposition goldens were captured from the pre-fix
 * code); the difference is undo granularity — one kill is now ONE undo
 * frame — and allocation: a motion is one clone, not one per column.
 */
final class EmacsKillRangeTest extends TestCase
{
    private function typed(string $text, bool $withMode = true): TextPrompt
    {
        $p = TextPrompt::new('> ')->withUndoManager(new UndoManager());
        if ($withMode) {
            $p = $p->withMode(new EmacsMode());
        }
        foreach (mb_str_split($text) as $ch) {
            $p = $p->handleChar($ch);
        }

        return $p;
    }

    public function testCtrlWKillUndoesInOneStep(): void
    {
        $p = $this->typed('alpha betakillme')->handleKey('ctrl_w');
        // GNU kill-the-word consumes the whitespace in front of the word
        // too — matching the default map (parity test below), byte for byte.
        $this->assertSame('alpha ', $p->value());
        $this->assertSame(6, $p->cursor());

        $restored = $p->handleKey('ctrl_z');
        $this->assertSame('alpha betakillme', $restored->value(), 'one kill = one undo frame');
    }

    public function testAltDKillAfterUndoesInOneStep(): void
    {
        $p = $this->typed('xray tail');
        for ($i = 0; $i < 9; $i++) {
            $p = $p->handleKey('left');
        }
        $p = $p->handleKey('alt_d');
        // wordAfter consumes the word AND its trailing space; the caret
        // stays at 0 (deleteRange anchors at `from`).
        $this->assertSame('tail', $p->value());
        $this->assertSame(0, $p->cursor());

        $this->assertSame('xray tail', $p->handleKey('ctrl_z')->value());
    }

    public function testModeKillMatchesDefaultMapKill(): void
    {
        $mode = $this->typed('hello world!')->handleKey('ctrl_w');
        $plain = $this->typed('hello world!', false)->handleKey('ctrl_w');
        $this->assertSame($plain->value(), $mode->value());
        $this->assertSame($plain->cursor(), $mode->cursor());
        $this->assertSame('hello world!', $mode->handleKey('ctrl_z')->value(), 'parity extends to undo granularity');
    }

    /** @return array<string, array{string, int, string, int}> */
    public static function transposeCases(): array
    {
        // [buffer, left-presses from end, expected value, expected cursor]
        // Captured from the PRE-FIX replay implementation: the range-delete
        // rewrite must reproduce every terminal state byte for byte.
        return [
            'pair at end' => ['ab', 0, 'ba', 2],
            'mid buffer' => ['abcd', 2, 'acbd', 3],
            'at home no-op' => ['abcde', 5, 'abcde', 0],
            'spaces count as chars' => ['a b c', 0, 'a bc ', 5],
            'one back' => ['abcd', 3, 'bacd', 2],
            'wide chars' => ['日x本', 2, 'x日本', 2],
        ];
    }

    #[DataProvider('transposeCases')]
    public function testTranspositionTerminalStates(string $text, int $left, string $want, int $wantCursor): void
    {
        $p = $this->typed($text);
        for ($i = 0; $i < $left; $i++) {
            $p = $p->handleKey('left');
        }
        $p = $p->handleKey('ctrl_t');
        $this->assertSame($want, $p->value());
        $this->assertSame($wantCursor, $p->cursor());
    }

    public function testWithCursorClampsAndIsIdentityOnNoMove(): void
    {
        $p = $this->typed('abc', false);
        $this->assertSame(3, $p->withCursor(99)->cursor());
        $this->assertSame(0, $p->withCursor(-5)->cursor());
        $one = $p->withCursor(1);
        $this->assertSame(1, $one->cursor());
        $this->assertSame($one, $one->withCursor(1), 'a no-op jump returns the same instance');
    }

    public function testEmptyRangeDeleteIsIdentity(): void
    {
        $p = $this->typed('abc', false);
        $this->assertSame($p, $p->deleteRange(2, 2));
        $this->assertSame($p, $p->deleteRange(3, 1));
    }

    public function testCaretJumpClosesTheInsertRun(): void
    {
        $p = $this->typed('ab', false);
        $p = $p->withCursor(0)->handleChar('X');
        $this->assertSame('Xab', $p->value());
        // One undo peels the post-jump insert only — it did not fold into
        // the 'ab' run, because the jump closed it.
        $this->assertSame('ab', $p->handleKey('ctrl_z')->value());

        // Distinguishing leg: a jump BACK to the old run-end must not
        // restore a stale fold target. Without the close, the 'c' insertion
        // folds into the pre-jump run and one Ctrl+Z wipes 'ab' with it;
        // with the close, only 'c' peels.
        $q = $this->typed('ab', false)->withCursor(0)->withCursor(2)->handleChar('c');
        $this->assertSame('abc', $q->value());
        $this->assertSame('ab', $q->handleKey('ctrl_z')->value());
    }

    public function testNoReplayWalksSurviveInEmacsMode(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../src/Mode/EmacsMode.php');
        // Single-press pass-throughs (Home/End/arrows routed from the key
        // map) stay legal; what must be gone is REPLAY machinery.
        $this->assertSame(0, preg_match('/handleKeyDirect\(Key::(?:Delete|Backspace)\)/', $src), 'no Delete-replay remains');
        $this->assertSame(0, substr_count($src, '$this->moveCursorTo('), 'the private walker is gone');
        $this->assertSame(0, substr_count($src, 'range(1,'), 'no count-driven walks remain');
        $this->assertGreaterThanOrEqual(2, substr_count($src, '->withCursor('));
        $this->assertGreaterThanOrEqual(3, substr_count($src, '->deleteRange('));

        $tp = (string) file_get_contents(__DIR__ . '/../../src/TextPrompt.php');
        $this->assertSame(1, substr_count($tp, 'return $this->withCursor($position);'), 'the private mover delegates to the public setter — one implementation');
    }
}

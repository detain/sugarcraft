<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\TextArea;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\PasteMsg;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Forms\TextArea\TextArea;
use SugarCraft\Forms\Vim\VimState;

/**
 * TUI-3B — the shared Vim layer ported onto TextArea.
 *
 * Drives the REAL model through update(): normal-mode motion (including the
 * new vertical CursorUp/CursorDown vocabulary), line-relative insert entry,
 * line-local word motions and `x`, character-wise visual selection spanning
 * rows and soft-wrapped pieces, and the as-is port surface (pending
 * operators stay unconsumed, exactly like TextInput; visual-line direction
 * awaits the E736 3.2 / R4 owner ruling).
 */
final class TextAreaVimModeTest extends TestCase
{
    /**
     * @param list<string> $lines
     */
    private function vimArea(array $lines = [], int $width = 0): TextArea
    {
        $t = TextArea::new()->withVimMode(true);
        [$t, ] = $t->focus();
        if ($width > 0) {
            $t = $t->withWidth($width);
        }
        if ($lines !== []) {
            $t = $t->setValue(implode("\n", $lines));
        }
        return $t;
    }

    private function key(TextArea $t, string $rune, bool $ctrl = false): TextArea
    {
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, $rune, ctrl: $ctrl));
        return $t;
    }

    private function arrow(TextArea $t, KeyType $type): TextArea
    {
        [$t, ] = $t->update(new KeyMsg($type));
        return $t;
    }

    /** @return array{0: TextArea, 1: ?\Closure} */
    private function keyWithCmd(TextArea $t, string $rune, bool $ctrl = false): array
    {
        return $t->update(new KeyMsg(KeyType::Char, $rune, ctrl: $ctrl));
    }

    // ═══════════════════════════════════════════════════════════════
    // Arming + dormancy polarity
    // ═══════════════════════════════════════════════════════════════

    public function testVimModeDisabledByDefault(): void
    {
        $t = TextArea::new();
        $this->assertFalse($t->vimMode);
        $this->assertSame(VimState::Normal, $t->vimState);
    }

    public function testVimModeStartsInNormalMode(): void
    {
        $t = TextArea::new()->withVimMode(true);
        $this->assertTrue($t->vimMode);
        $this->assertSame(VimState::Normal, $t->vimState);
    }

    public function testVimModeDisabledInsertsTheHKey(): void
    {
        $t = TextArea::new();
        [$t, ] = $t->focus();
        $t = $this->key($t, 'h');
        $this->assertSame('h', $t->value());
    }

    public function testVimModeDisabledInsertsTheXKey(): void
    {
        $t = TextArea::new();
        [$t, ] = $t->focus();
        $t = $this->key($t, 'x');
        $this->assertSame('x', $t->value());
    }

    public function testVimModeTurnedOffRestoresTyping(): void
    {
        // setValue parks the caret at buffer end — the h lands there.
        $t = $this->vimArea(['hello'])->withVimMode(false);
        $t = $this->key($t, 'h');
        $this->assertSame('helloh', $t->value());
    }

    public function testShortAliasMatchesWithVimMode(): void
    {
        $t = TextArea::new()->vimMode(true);
        $this->assertTrue($t->vimMode);
        $this->assertSame(VimState::Normal, $t->vimState);
    }

    // ═══════════════════════════════════════════════════════════════
    // Normal mode — horizontal motion (TextInput parity, but crossing
    // rows exactly like the built-in arrows do)
    // ═══════════════════════════════════════════════════════════════

    public function testNormalModeHKeyMovesLeft(): void
    {
        $t = $this->vimArea(['hello world']);
        $t = $this->key($t, 'h');
        $this->assertSame(10, $t->col);
        $this->assertSame(0, $t->row);
    }

    public function testNormalModeLKeyAtEndOfLineCrossesToNextRow(): void
    {
        // Multi-line divergence from TextInput (which clamps at end): the
        // built-in Right arrow crosses rows, and `l` re-reads as that arrow.
        $t = $this->vimArea(['ab', 'cd'])->setCursor(0, 2);
        $t = $this->key($t, 'l');
        $this->assertSame(1, $t->row);
        $this->assertSame(0, $t->col);
    }

    public function testNormalModeHKeyAtStartOfLineCrossesToPreviousRow(): void
    {
        $t = $this->vimArea(['ab', 'cd'])->setCursor(1, 0);
        $t = $this->key($t, 'h');
        $this->assertSame(0, $t->row);
        $this->assertSame(2, $t->col);
    }

    public function testNormalModeHKeyAtBufferStartNoOp(): void
    {
        $t = $this->vimArea(['ab'])->setCursor(0, 0);
        $before = $t;
        $t = $this->key($t, 'h');
        $this->assertSame(0, $t->row);
        $this->assertSame(0, $t->col);
        $this->assertEquals($before, $t);
    }

    // ═══════════════════════════════════════════════════════════════
    // Normal mode — vertical motion (TUI-3B: j DOWN, k UP, the direction
    // the app actually uses)
    // ═══════════════════════════════════════════════════════════════

    public function testNormalModeJMovesDownOneRow(): void
    {
        $t = $this->vimArea(['one', 'two', 'three'])->setCursor(0, 1);
        $t = $this->key($t, 'j');
        $this->assertSame(1, $t->row);
        $this->assertSame(1, $t->col); // column preserved, clamped to line
    }

    public function testNormalModeKMovesUpOneRow(): void
    {
        $t = $this->vimArea(['one', 'two', 'three'])->setCursor(2, 2);
        $t = $this->key($t, 'k');
        $this->assertSame(1, $t->row);
        $this->assertSame(2, $t->col);
    }

    public function testNormalModeJClampsAtLastRow(): void
    {
        $t = $this->vimArea(['one', 'two']);
        $t = $this->key($t, 'j');
        $this->assertSame(1, $t->row);
    }

    public function testNormalModeKClampsAtFirstRow(): void
    {
        $t = $this->vimArea(['one', 'two'])->setCursor(0, 1);
        $t = $this->key($t, 'k');
        $this->assertSame(0, $t->row);
    }

    public function testVerticalMotionIsCaseInsensitive(): void
    {
        $t = $this->vimArea(['one', 'two', 'three'])->setCursor(0, 0);
        $t = $this->key($t, 'J');
        $this->assertSame(1, $t->row);
    }

    public function testNormalModeArrowsMoveRowsNotHistory(): void
    {
        // TextArea has no history model; the HistoryUp/HistoryDown spelling
        // the handler gives the arrows re-reads here as row motion.
        $t = $this->vimArea(['a', 'b', 'c'])->setCursor(1, 1);
        $t = $this->arrow($t, KeyType::Up);
        $this->assertSame(0, $t->row);
        $t = $this->arrow($t, KeyType::Down);
        $this->assertSame(1, $t->row);
    }

    // ═══════════════════════════════════════════════════════════════
    // Normal mode — line-local word motion and bounds
    // ═══════════════════════════════════════════════════════════════

    public function testNormalModeZeroGoesToStartOfCurrentLine(): void
    {
        $t = $this->vimArea(['hello world', 'second'])->setCursor(1, 3);
        $t = $this->key($t, '0');
        $this->assertSame(1, $t->row);
        $this->assertSame(0, $t->col);
    }

    public function testNormalModeDollarGoesToEndOfCurrentLine(): void
    {
        $t = $this->vimArea(['hello world', 'second'])->setCursor(0, 2);
        $t = $this->key($t, '$');
        $this->assertSame(0, $t->row);
        $this->assertSame(11, $t->col);
    }

    public function testNormalModeWMovesWordForwardOnTheLine(): void
    {
        $t = $this->vimArea(['one two three', 'next line'])->setCursor(0, 0);
        $t = $this->key($t, 'w');
        $this->assertSame(0, $t->row);
        $this->assertSame(4, $t->col);
        $t = $this->key($t, 'w');
        $this->assertSame(8, $t->col);
    }

    public function testNormalModeWDoesNotCrossLines(): void
    {
        // Line-local by design (documented port rule): w at end of a row is a
        // no-op, it does not wrap to the next row's first word.
        $t = $this->vimArea(['one', 'two'])->setCursor(0, 3);
        $t = $this->key($t, 'w');
        $this->assertSame(0, $t->row);
        $this->assertSame(3, $t->col);
    }

    public function testNormalModeBMovesWordBackward(): void
    {
        // b from INSIDE a word lands on that word's start (TextInput's twin
        // semantics): col 9 sits in "three" (starts at 8).
        $t = $this->vimArea(['one two three'])->setCursor(0, 9);
        $t = $this->key($t, 'b');
        $this->assertSame(8, $t->col);
    }

    public function testWordMotionWithPunctuation(): void
    {
        $t = $this->vimArea(['foo.bar baz'])->setCursor(0, 0);
        $t = $this->key($t, 'w');
        $this->assertSame(4, $t->col); // past '.' onto 'bar'
    }

    // ═══════════════════════════════════════════════════════════════
    // Insert entry + insert mode
    // ═══════════════════════════════════════════════════════════════

    public function testIEntersInsertModeAtCaret(): void
    {
        $t = $this->vimArea(['abc'])->setCursor(0, 1);
        $t = $this->key($t, 'i');
        $this->assertSame(VimState::Insert, $t->vimState);
        $this->assertSame(1, $t->col);
        $t = $this->key($t, 'Z');
        $this->assertSame('aZbc', $t->value());
    }

    public function testAEntersInsertModeOneRight(): void
    {
        $t = $this->vimArea(['abc'])->setCursor(0, 0);
        $t = $this->key($t, 'a');
        $this->assertSame(VimState::Insert, $t->vimState);
        $this->assertSame(1, $t->col);
    }

    public function testACapsAtEndOfCurrentLine(): void
    {
        $t = $this->vimArea(['abc', 'de'])->setCursor(0, 2);
        $t = $this->key($t, 'a');
        $this->assertSame(3, $t->col); // col+1 lands exactly on end-of-line
        $this->assertSame(0, $t->row);
    }

    public function testBigAAppendsAtEndOfCurrentLine(): void
    {
        $t = $this->vimArea(['hello world', 'second'])->setCursor(0, 2);
        $t = $this->key($t, 'A');
        $this->assertSame(VimState::Insert, $t->vimState);
        $this->assertSame(0, $t->row);
        $this->assertSame(11, $t->col);
        $t = $this->key($t, '!');
        $this->assertSame('hello world!', $t->lines[0]);
    }

    public function testBigIInsertsAtStartOfCurrentLine(): void
    {
        $t = $this->vimArea(['x', 'hello world'])->setCursor(1, 4);
        $t = $this->key($t, 'I');
        $this->assertSame(VimState::Insert, $t->vimState);
        $this->assertSame(1, $t->row);
        $this->assertSame(0, $t->col);
    }

    public function testEscapeReturnsToNormalModeFromInsert(): void
    {
        $t = $this->vimArea(['abc'])->setCursor(0, 1);
        $t = $this->key($t, 'i');
        $t = $t->update(new KeyMsg(KeyType::Escape))[0];
        $this->assertSame(VimState::Normal, $t->vimState);
        // h now moves instead of inserting
        $t = $this->key($t, 'h');
        $this->assertSame('abc', $t->value());
        $this->assertSame(0, $t->col);
    }

    public function testEscapeInNormalModeStaysNormal(): void
    {
        $t = $this->vimArea(['abc']);
        $t = $t->update(new KeyMsg(KeyType::Escape))[0];
        $this->assertSame(VimState::Normal, $t->vimState);
        $this->assertSame('abc', $t->value());
    }

    public function testInsertModeEnterSplitsLine(): void
    {
        $t = $this->vimArea(['abcd'])->setCursor(0, 2);
        $t = $this->key($t, 'i');
        $t = $t->update(new KeyMsg(KeyType::Enter))[0];
        $this->assertSame(['ab', 'cd'], $t->lines);
        $this->assertSame(1, $t->row);
        $this->assertSame(0, $t->col);
        $this->assertSame(VimState::Insert, $t->vimState);
    }

    public function testInsertModeTabInsertsFourSpaces(): void
    {
        $t = $this->vimArea(['x'])->setCursor(0, 1);
        $t = $this->key($t, 'i');
        $t = $t->update(new KeyMsg(KeyType::Tab))[0];
        $this->assertSame('x    ', $t->value());
    }

    public function testInsertModeBackspaceAtLineStartMergesRows(): void
    {
        $t = $this->vimArea(['ab', 'cd'])->setCursor(1, 0);
        $t = $this->key($t, 'i');
        $t = $t->update(new KeyMsg(KeyType::Backspace))[0];
        $this->assertSame(['abcd'], $t->lines);
    }

    public function testInsertModeArrowsMoveRows(): void
    {
        $t = $this->vimArea(['a', 'b'])->setCursor(0, 1);
        $t = $this->key($t, 'i');
        $t = $this->arrow($t, KeyType::Down);
        $this->assertSame(1, $t->row);
    }

    public function testInsertModeCtrlChordsStayLineRelative(): void
    {
        $t = $this->vimArea(['hello world', 'keep'])->setCursor(0, 5);
        $t = $this->key($t, 'i');
        $t = $this->key($t, 'u', ctrl: true); // delete to line start
        $this->assertSame(' world', $t->lines[0]);
        $this->assertSame(0, $t->col);
        $t = $this->key($t, 'k', ctrl: true); // delete to line end
        $this->assertSame('', $t->lines[0]);
        $this->assertSame('keep', $t->lines[1]);
        $t = $this->key($t, 'e', ctrl: true);
        $this->assertSame(0, $t->col); // caret on row 0 (empty), line end = 0
        $t = $t->update(new KeyMsg(KeyType::End))[0];
        $this->assertSame(0, $t->col);
    }

    // ═══════════════════════════════════════════════════════════════
    // x — delete char under cursor
    // ═══════════════════════════════════════════════════════════════

    public function testNormalModeXDeletesCharUnderCursor(): void
    {
        $t = $this->vimArea(['hello'])->setCursor(0, 0);
        $t = $this->key($t, 'x');
        $this->assertSame('ello', $t->value());
    }

    public function testNormalModeXAtLineEndRefusesAndNeverMergesRows(): void
    {
        // The documented divergence from the Delete KEY (deleteForward merges
        // rows at EOL): vim `x` beeps at end of line, it never eats a row.
        $t = $this->vimArea(['ab', 'cd'])->setCursor(0, 2);
        $t = $this->key($t, 'x');
        $this->assertSame(['ab', 'cd'], $t->lines);
    }

    public function testNormalModeXIsMultibyteSafe(): void
    {
        $t = $this->vimArea(['a日b'])->setCursor(0, 1);
        $t = $this->key($t, 'x');
        $this->assertSame('ab', $t->value());
    }

    public function testNormalModeXOnlyTouchesTheCaretLine(): void
    {
        $t = $this->vimArea(['one', 'two'])->setCursor(1, 0);
        $t = $this->key($t, 'x');
        $this->assertSame(['one', 'wo'], $t->lines);
    }

    // ═══════════════════════════════════════════════════════════════
    // Visual mode — character-wise selection over the anchor model
    // ═══════════════════════════════════════════════════════════════

    public function testVEntersVisualModeWithZeroWidthAnchor(): void
    {
        $t = $this->vimArea(['hello world'])->setCursor(0, 0);
        $t = $this->key($t, 'v');
        $this->assertSame(VimState::Visual, $t->vimState);
        $this->assertFalse($t->hasSelection()); // anchor == caret
    }

    public function testVisualModeBigVOpensTheSameCharacterWiseModePendingR4(): void
    {
        // The handler lowercases keys, so 'V' arrives as EnterVisualMode; this
        // port deliberately opens character-wise visual for it too. Line-wise
        // entry is held back for the E736 3.2 / R4 j/k direction ruling.
        $t = $this->vimArea(['hello'])->setCursor(0, 0);
        $t = $this->key($t, 'V');
        $this->assertSame(VimState::Visual, $t->vimState);
    }

    public function testVisualModeHorizontalMotionExtendsSelection(): void
    {
        $t = $this->vimArea(['hello world'])->setCursor(0, 0);
        $t = $this->key($t, 'v');
        for ($i = 0; $i < 5; $i++) {
            $t = $this->key($t, 'l');
        }
        $this->assertSame('hello', $t->selectedText());
    }

    public function testVisualModeVerticalMotionSelectsAcrossRows(): void
    {
        $t = $this->vimArea(['abcd', 'efgh', 'ijkl'])->setCursor(0, 1);
        $t = $this->key($t, 'v');
        $t = $this->key($t, 'j');
        $t = $this->key($t, 'j');
        // anchor (0,1); cursorDown preserves the column, so the caret lands at
        // (2,1) — the span end is EXCLUSIVE there: bcd / whole efgh / "i".
        $this->assertSame(2, $t->row);
        $this->assertSame(1, $t->col);
        $this->assertSame("bcd\nefgh\ni", $t->selectedText());
    }

    public function testVisualModeBackwardsSelectionNormalises(): void
    {
        $t = $this->vimArea(['hello world'])->setCursor(0, 5);
        $t = $this->key($t, 'v');
        $t = $this->key($t, 'h');
        $t = $this->key($t, 'h');
        $this->assertSame('lo', $t->selectedText());
    }

    public function testVisualModeWordAndLineBoundsExtend(): void
    {
        $t = $this->vimArea(['one two three'])->setCursor(0, 0);
        $t = $this->key($t, 'v');
        $t = $this->key($t, 'w');
        $this->assertSame('one ', $t->selectedText());
        $t = $this->key($t, '$');
        $this->assertSame('one two three', $t->selectedText());
    }

    public function testVisualEscapeCancelsSelectionAndReturnsToNormal(): void
    {
        $t = $this->vimArea(['hello world'])->setCursor(0, 0);
        $t = $this->key($t, 'v');
        $t = $this->key($t, 'l');
        $this->assertTrue($t->hasSelection());
        $t = $t->update(new KeyMsg(KeyType::Escape))[0];
        $this->assertSame(VimState::Normal, $t->vimState);
        $this->assertFalse($t->hasSelection());
    }

    public function testVisualSelectionRendersReverseVideoAcrossWrappedPieces(): void
    {
        // width 6 splits "alpha betagamma" into pieces [0,6) "alpha ",
        // [6,12) "betaga", [12,15) "mma"; selecting cols 0..13 must paint the
        // whole middle wrapped piece in reverse video.
        $t = $this->vimArea(['alpha betagamma'], width: 6)->setCursor(0, 0);
        $t = $this->key($t, 'v');
        for ($i = 0; $i < 13; $i++) {
            $t = $this->key($t, 'l');
        }
        $view = $t->view();
        $this->assertStringContainsString(
            Ansi::sgr(Ansi::REVERSE) . 'betaga' . Ansi::reset(),
            $view,
        );
        $this->assertSame('alpha betagam', $t->selectedText());
    }

    public function testVisualModeCopyChordStillCarriesTheSelection(): void
    {
        // Ctrl+C is the widget's clipboard contract (E736 5.13), not a vim
        // binding, so it reaches the OSC-52 Cmd from inside visual mode.
        $t = $this->vimArea(['copy me'])->setCursor(0, 0);
        $t = $this->key($t, 'v');
        $t = $this->key($t, 'w');
        [$t, $cmd] = $this->keyWithCmd($t, 'c', ctrl: true);
        $this->assertNotNull($cmd);
        $this->assertSame(VimState::Visual, $t->vimState); // copy leaves mode alone
    }

    public function testVisualModeCutChordDeletesTheSpan(): void
    {
        $t = $this->vimArea(['copy me'])->setCursor(0, 0);
        $t = $this->key($t, 'v');
        $t = $this->key($t, 'w');
        $t = $this->key($t, 'x', ctrl: true);
        $this->assertSame('me', $t->value());
    }

    // ═══════════════════════════════════════════════════════════════
    // Port surface — what stays deliberately unconsumed
    // ═══════════════════════════════════════════════════════════════

    public function testPendingOperatorVocabularyIsUnconsumedParityWithTextInput(): void
    {
        // dd/yy/p/u ride the same operator family TextInput leaves unhandled
        // (the layer documents pending-operator tracking as consumer work that
        // neither widget has built yet). Pin the AS-IS port surface: buffer
        // untouched, mode untouched.
        $t = $this->vimArea(['stay', 'put']);
        $t = $this->key($t, 'd');
        $t = $this->key($t, 'd');
        $t = $this->key($t, 'y');
        $t = $this->key($t, 'y');
        $t = $this->key($t, 'p');
        $t = $this->key($t, 'u');
        $this->assertSame("stay\nput", $t->value());
        $this->assertSame(VimState::Normal, $t->vimState);
    }

    public function testUnmappedNormalModeCharBeeps(): void
    {
        $t = $this->vimArea(['stay']);
        $before = $t;
        $t = $this->key($t, 'q');
        $this->assertSame('stay', $t->value());
        $this->assertEquals($before, $t);
    }

    public function testPasteMsgInsertsRegardlessOfVimMode(): void
    {
        // Bracketed paste is not a KeyMsg — the existing 5.13 arm owns it and
        // vim mode must not swallow it.
        $t = $this->vimArea(['head'])->setCursor(0, 4);
        [$t, ] = $t->update(new PasteMsg(' tail'));
        $this->assertSame('head tail', $t->value());
        $this->assertSame(VimState::Normal, $t->vimState);
    }

    // ═══════════════════════════════════════════════════════════════
    // Door + lifecycle order
    // ═══════════════════════════════════════════════════════════════

    public function testHostKeyOverrideWinsOverVimMode(): void
    {
        // Same seam order TextInput ships: overrides > vim > built-ins.
        $t = $this->vimArea(['hello world'])->setCursor(0, 2);
        // (trait constants are not directly accessible — the door speaks the
        // canonical action strings)
        $t = $t->withKeyOverrides(['j' => 'move_end']);
        $t = $this->key($t, 'j');
        // The override's MOVE_END is row-relative: end of the CURRENT line,
        // not the vim row-down that would otherwise fire.
        $this->assertSame(0, $t->row);
        $this->assertSame(11, $t->col);
    }

    public function testBlurResetsInsertModeToNormal(): void
    {
        $t = $this->vimArea(['abc']);
        $t = $this->key($t, 'i');
        $this->assertSame(VimState::Insert, $t->vimState);
        $t = $t->blur();
        $this->assertSame(VimState::Normal, $t->vimState);
    }

    public function testUnfocusedVimModeSwallowsKeysEntirely(): void
    {
        $t = TextArea::new()->withVimMode(true)->setValue('abc');
        $before = $t;
        $t = $this->key($t, 'h');
        $this->assertEquals($before, $t);
    }

    public function testVimModeWithersAreImmutableAndCarryState(): void
    {
        $t = $this->vimArea(['abc'])->setCursor(0, 1);
        $t = $this->key($t, 'i');
        $armed = $t->withCharLimit(50); // unrelated wither must preserve vim state
        $this->assertSame(VimState::Insert, $armed->vimState);
        $this->assertTrue($armed->vimMode);
    }

    // ═══════════════════════════════════════════════════════════════
    // Non-vim path regression guard — the port must be inert when off
    // ═══════════════════════════════════════════════════════════════

    public function testDisabledVimKeepsBuiltInKeyHandlingByteForByte(): void
    {
        $plain = TextArea::new()->setValue("one\ntwo");
        [$plain, ] = $plain->focus();
        $plainAfter = $plain
            ->update(new KeyMsg(KeyType::Char, 'j'))[0]
            ->update(new KeyMsg(KeyType::Char, 'k'))[0];
        // Without vim mode these are literal insertions at the caret (end of
        // buffer), NOT row motion — the dormant path never sees VimKeyHandler.
        $this->assertSame("one\ntwojk", $plainAfter->value());
        $this->assertSame(1, $plainAfter->row);
        $this->assertSame(5, $plainAfter->col);
    }
}

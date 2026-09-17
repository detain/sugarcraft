<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\TextArea;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\PasteMsg;
use SugarCraft\Core\RawMsg;
use SugarCraft\Forms\TextArea\TextArea;

/**
 * E736 5.13 (round 88): selection model + clipboard. Copy/cut ride the
 * Cmd channel as `Cmd::setClipboard` (OSC 52 → RawMsg) — the model never
 * writes to a stream; paste arrives as the host-dispatched PasteMsg and
 * replaces the selection. Selection spans render reverse-video while the
 * widget is focused.
 */
final class TextAreaClipboardTest extends TestCase
{
    private const REV = "\x1b[7m";
    private const OFF = "\x1b[0m";

    private function focused(string $initial = ''): TextArea
    {
        $t = TextArea::new();
        if ($initial !== '') {
            $t = $t->setValue($initial);
        }
        [$t, ] = $t->focus();
        return $t;
    }

    private function ctrl(string $rune): KeyMsg
    {
        return new KeyMsg(KeyType::Char, $rune, ctrl: true);
    }

    // ---- selection model ----------------------------------------------

    public function testInitialStateHasNoSelection(): void
    {
        $t = $this->focused('hello');
        $this->assertFalse($t->hasSelection());
        $this->assertSame('', $t->selectedText());
        $this->assertNull($t->anchorRow);
        $this->assertNull($t->anchorCol);
    }

    public function testWithSelectAndCaretDelimitSpan(): void
    {
        $t = $this->focused('hello')->withSelect(0, 1)->setCursor(0, 3);
        $this->assertTrue($t->hasSelection());
        $this->assertSame('el', $t->selectedText());
    }

    public function testBackwardsSelectionNormalizesToSameSpan(): void
    {
        $fwd = $this->focused('hello')->withSelect(0, 1)->setCursor(0, 4);
        $bwd = $this->focused('hello')->withSelect(0, 4)->setCursor(0, 1);
        $this->assertSame('ell', $fwd->selectedText());
        $this->assertSame($fwd->selectedText(), $bwd->selectedText());
    }

    public function testReanchorAtCaretClearsSpan(): void
    {
        $t = $this->focused('hello')->withSelect(0, 1)->setCursor(0, 3);
        $cleared = $t->withSelect(0, 3);
        $this->assertFalse($cleared->hasSelection());
        $this->assertSame('', $cleared->selectedText());
    }

    public function testMultiRowSelectionJoinsWithNewlines(): void
    {
        $t = $this->focused("aaa\nbbb\nccc")->withSelect(0, 1)->setCursor(2, 2);
        $this->assertSame("aa\nbbb\ncc", $t->selectedText());
    }

    public function testWithSelectCoercesOutOfRangeCells(): void
    {
        $t = $this->focused('hello');
        $clamped = $t->withSelect(-5, -9);
        $this->assertSame(0, $clamped->anchorRow);
        $this->assertSame(0, $clamped->anchorCol);
        $over = $t->withSelect(99, 99)->setCursor(0, 5);
        // anchor row clamps to the last line, col to that line's length —
        // which is exactly the caret, so the span is empty, never dangling.
        $this->assertFalse($over->hasSelection());
    }

    public function testClearSelectionIsIdempotentAndLeavesCaret(): void
    {
        $t = $this->focused('hello')->withSelect(0, 1);
        $c1 = $t->clearSelection();
        $this->assertFalse($c1->hasSelection());
        $this->assertNull($c1->anchorRow);
        $this->assertSame(0, $c1->row);
        $this->assertSame(5, $c1->col);
        $this->assertSame($c1, $c1->clearSelection());
    }

    public function testSetValueAndResetDropTheAnchor(): void
    {
        $t = $this->focused('hello')->withSelect(0, 1);
        $this->assertTrue($t->hasSelection());
        $this->assertFalse($t->setValue('replacement')->hasSelection());
        $this->assertFalse($t->reset()->hasSelection());
    }

    // ---- render ---------------------------------------------------------

    public function testSelectionRendersAsReverseVideoSpan(): void
    {
        // 'hello' with the caret at col 3: 'el' carries the selection run,
        // the caret cell keeps its own (primitive-rendered) reverse video.
        $t = $this->focused('hello')->withSelect(0, 1)->setCursor(0, 3);
        $this->assertSame(
            'h' . self::REV . 'el' . self::OFF . self::REV . 'l' . self::OFF . 'o',
            $t->view(),
        );
    }

    public function testMultiRowSelectionPaintsEachRowFully(): void
    {
        $t = $this->focused("aaa\nbbb\nccc")->withSelect(0, 1)->setCursor(2, 2);
        // caret row: 'cc' painted [0,2), caret cell on the trailing 'c'.
        $this->assertSame(
            'a' . self::REV . 'aa' . self::OFF . "\n"
            . self::REV . 'bbb' . self::OFF . "\n"
            . self::REV . 'cc' . self::OFF . self::REV . 'c' . self::OFF,
            $t->view(),
        );
    }

    public function testUnfocusedSelectionDoesNotPaint(): void
    {
        $t = $this->focused('hello')->withSelect(0, 1)->setCursor(0, 3)->blur();
        $this->assertSame('hello', $t->view());
    }

    public function testNoSelectionRendersByteIdenticalToPreClipboardPath(): void
    {
        $t = $this->focused('hello')->setCursor(0, 3);
        // Cursor primitive paints the caret cell reverse.
        $this->assertSame('hel' . self::REV . 'l' . self::OFF . 'o', $t->view());
    }

    public function testSelectionSlicesCodepointsNotBytes(): void
    {
        $t = $this->focused('日本語')->withSelect(0, 0)->setCursor(0, 2);
        $this->assertSame('日本', $t->selectedText());
        $this->assertSame(
            self::REV . '日本' . self::OFF . self::REV . '語' . self::OFF,
            $t->view(),
        );
    }

    // ---- copy / cut / paste via update() --------------------------------

    public function testCtrlCCopiesSelectionViaOsc52Cmd(): void
    {
        $t = $this->focused('hello world')->withSelect(0, 0)->setCursor(0, 5);
        [$next, $cmd] = $t->update($this->ctrl('c'));
        $this->assertNotNull($cmd);
        $this->assertSame($t, $next);
        $msg = $cmd();
        $this->assertInstanceOf(RawMsg::class, $msg);
        $this->assertSame("\x1b]52;c;" . base64_encode('hello') . "\x07", $msg->bytes);
        $this->assertTrue($next->hasSelection()); // copy is non-destructive
    }

    public function testCtrlCCopyNoOpOnEmptySelection(): void
    {
        $t = $this->focused('abc');
        [$next, $cmd] = $t->update($this->ctrl('c'));
        $this->assertSame($t, $next);
        $this->assertNull($cmd);
    }

    public function testCtrlXCutCopiesThenDeletesAndDropsAnchor(): void
    {
        $t = $this->focused('hello world')->withSelect(0, 0)->setCursor(0, 5);
        [$next, $cmd] = $t->update($this->ctrl('x'));
        $this->assertNotNull($cmd);
        $this->assertSame("\x1b]52;c;" . base64_encode('hello') . "\x07", $cmd()->bytes);
        $this->assertSame(' world', $next->value());
        $this->assertSame(0, $next->row);
        $this->assertSame(0, $next->col);
        $this->assertFalse($next->hasSelection());
    }

    public function testCtrlXAcrossRowsJoinsSurvivingEnds(): void
    {
        $t = $this->focused("aaa\nbbb\nccc")->withSelect(0, 1)->setCursor(2, 2);
        [$next, $cmd] = $t->update($this->ctrl('x'));
        $this->assertSame("\x1b]52;c;" . base64_encode("aa\nbbb\ncc") . "\x07", $cmd()->bytes);
        $this->assertSame('ac', $next->value());
        $this->assertSame([0, 1], [$next->row, $next->col]);
    }

    public function testCutNoOpOnEmptySelection(): void
    {
        $t = $this->focused('abc');
        [$next, $cmd] = $t->update($this->ctrl('x'));
        $this->assertSame($t, $next);
        $this->assertNull($cmd);
    }

    public function testPasteReplacesSelectionAndInsertsAtCaret(): void
    {
        $t = $this->focused('hello world')->withSelect(0, 0)->setCursor(0, 5);
        [$next, $cmd] = $t->update(new PasteMsg('XY'));
        $this->assertNull($cmd);
        $this->assertSame('XY world', $next->value());
        $this->assertSame(0, $next->row);
        $this->assertSame(2, $next->col); // caret lands at the end of the insert
        $this->assertFalse($next->hasSelection());
    }

    public function testPasteWithoutSelectionIsPlainInsert(): void
    {
        $t = $this->focused('ab')->setCursor(0, 1);
        [$next, ] = $t->update(new PasteMsg("1\n2"));
        // Multi-line paste splits rows through the existing insertString
        // seam: '1' before the newline lands on row 0, '2' continues row 1.
        $this->assertSame("a1\n2b", $next->value());
        $this->assertSame(1, $next->row);
        $this->assertSame(1, $next->col); // caret at the end of the payload
    }

    public function testPasteDroppedWhenUnfocused(): void
    {
        $t = TextArea::new()->setValue('z');
        [$next, $cmd] = $t->update(new PasteMsg('Q'));
        $this->assertSame($t, $next);
        $this->assertNull($cmd);
    }

    public function testSelectionSurvivesPlainTyping(): void
    {
        // Documented deviation from GUI editors: the anchor is inert state —
        // typing does NOT consume the selection, only cut/paste do. Pinning
        // this keeps the keyboard edit paths byte-identical.
        $t = $this->focused('hello')->withSelect(0, 1)->setCursor(0, 3);
        [$next, ] = $t->update(new KeyMsg(KeyType::Char, '!'));
        $this->assertTrue($next->hasSelection());
        $this->assertSame('hel!lo', $next->value());
    }

    public function testCtrlCopyUnaffectedOnUnfocusedWidget(): void
    {
        $t = TextArea::new()->setValue('hello')->withSelect(0, 0)->setCursor(0, 2);
        [$next, $cmd] = $t->update($this->ctrl('c'));
        $this->assertSame($t, $next);
        $this->assertNull($cmd);
    }
}

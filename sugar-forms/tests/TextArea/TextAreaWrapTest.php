<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\TextArea;

use SugarCraft\Core\Util\Width;
use SugarCraft\Forms\TextArea\TextArea;
use PHPUnit\Framework\TestCase;

/**
 * E736 F1 — the configured width now soft-wraps the render by DISPLAY CELLS
 * (wide glyphs count their true width), the caret resolves into the wrapped
 * visual row that owns it, and `visualColumn()` reports the cell x the paint
 * uses while `column()` stays the codepoint offset the flat draft math relies
 * on. A width of 0 keeps the legacy unbounded render byte-identical.
 */
final class TextAreaWrapTest extends TestCase
{
    private const REV = "\x1b[7m";
    private const OFF = "\x1b[0m";

    public function testUnwrappedLegacyRenderStaysByteIdentical(): void
    {
        $t = TextArea::new()->setValue(str_repeat('a', 60));
        $this->assertSame(str_repeat('a', 60), $t->view());
    }

    public function testWrapsAsciiLinesAtConfiguredWidth(): void
    {
        $t = TextArea::new()->setValue(str_repeat('a', 60))->withWidth(20);
        $rows = explode("\n", $t->view());
        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertSame(20, Width::string($row));
        }
        $this->assertSame([str_repeat('a', 20), str_repeat('a', 20), str_repeat('a', 20)], $rows);
    }

    public function testWideCharsWrapByDisplayCellsNotCodepoints(): void
    {
        $t = TextArea::new()->setValue(str_repeat('日', 5))->withWidth(4);
        $rows = explode("\n", $t->view());
        $this->assertSame(['日日', '日日', '日'], $rows);
        $this->assertSame([4, 4, 2], array_map([Width::class, 'string'], $rows));
    }

    public function testOverWideFirstClusterStillAdvancesTheWalk(): void
    {
        // Width 1 cannot hold a 2-cell glyph; the row over-flows rather than
        // hanging or dropping the character (progress law of Width::truncate).
        $t = TextArea::new()->setValue('a日')->withWidth(1);
        $this->assertSame(['a', '日'], explode("\n", $t->view()));
    }

    public function testCaretResolvesToTheVisualRowThatOwnsIt(): void
    {
        $t = $this->focusedTextarea('aaaaBBBB', 4)->setCursor(0, 5);
        $rows = explode("\n", $t->view());
        $this->assertCount(2, $rows);
        $this->assertStringNotContainsString(self::REV, $rows[0]);
        $this->assertSame('aaaa', $rows[0]);
        $this->assertSame('B' . self::REV . 'B' . self::OFF . 'BB', $rows[1]);
    }

    public function testCaretAtLineEndRidesTheLastVisualRow(): void
    {
        $t = $this->focusedTextarea('aaaaBBBB', 4)->setCursor(0, 8);
        $rows = explode("\n", $t->view());
        $this->assertSame('aaaa', $rows[0]);
        $this->assertSame('BBBB' . self::REV . ' ' . self::OFF, $rows[1]);
    }

    public function testCaretOnFirstWrappedRowUnaffected(): void
    {
        $t = $this->focusedTextarea('aaaaBBBB', 4)->setCursor(0, 2);
        $rows = explode("\n", $t->view());
        $this->assertSame('aa' . self::REV . 'a' . self::OFF . 'a', $rows[0]);
        $this->assertSame('BBBB', $rows[1]);
    }

    public function testGutterAppliesOnceAndContinuationRowsAlign(): void
    {
        $t = TextArea::new()->setValue('aaaaBBBB')->withWidth(4)->withPrompt('> ');
        $this->assertSame('> aaaa' . "\n" . '  BBBB', $t->view());
    }

    public function testVisualColumnCountsCellsWhileColumnCountsCodepoints(): void
    {
        $t = TextArea::new()->setValue('a日b');

        $afterA = $t->setCursor(0, 1);
        $this->assertSame(1, $afterA->column());
        $this->assertSame(1, $afterA->visualColumn());

        // 'a日b' fully traversed: 3 codepoints, 4 display cells.
        $this->assertSame(3, $t->setCursor(0, 3)->column());
        $this->assertSame(4, $t->setCursor(0, 3)->visualColumn());

        // Between 'a日' and the trailing 'b'.
        $this->assertSame(3, $t->setCursor(0, 2)->visualColumn());
    }

    public function testSelectionSpanPaintsAcrossWrappedPieces(): void
    {
        $t = $this->focusedTextarea('aaaaBBBB', 4)
            ->withSelect(0, 6)
            ->setCursor(0, 2);
        $rows = explode("\n", $t->view());
        // Span [2,6): the caret row paints its cell + tail fragment as two
        // adjacent reverse runs; row 1 gets the [4,6) overlap on its head.
        $this->assertSame('aa' . self::REV . 'a' . self::OFF . self::REV . 'a' . self::OFF, $rows[0]);
        $this->assertSame(self::REV . 'BB' . self::OFF . 'BB', $rows[1]);
    }

    private function focusedTextarea(string $value, int $width): TextArea
    {
        $t = TextArea::new()->setValue($value)->withWidth($width);
        [$t, ] = $t->focus();
        return $t;
    }
}

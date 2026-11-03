<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Tests\Input;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Widgets\Input\TextEdit;
use SugarCraft\Widgets\Menu\OptionRow;

/**
 * Pins for the 2026-11 review findings: M20/M21 on TextEdit (raw bytes —
 * control sequences and forged mouse-zone sentinels — landed in the buffer
 * and rode straight into view()), and M17 on OptionRow::render (the value
 * line was built to the value column's budget and ignored $nameCol, so a
 * narrow name column let it overrun into the next one).
 */
final class InputAndMenuReviewPinsTest extends TestCase
{
    public function testInsertStripsForgedSentinelsAndControlBytes(): void
    {
        $edit = TextEdit::new("safe\u{E000}");
        self::assertStringNotContainsString("\u{E000}", $edit->text);

        $edit = $edit->insert("x\u{E001}y\u{1B}[31m");
        self::assertStringNotContainsString("\u{E001}", $edit->text);
        // The ESC itself must be gone (folded or stripped); the remaining
        // buffer is plain single-line text.
        self::assertStringNotContainsString("\u{1B}", $edit->text);
        self::assertStringNotContainsString("\n", $edit->text);

        $pasted = TextEdit::new()->insert("line1\nline2\ttab");
        self::assertStringNotContainsString("\n", $pasted->text);
        self::assertStringNotContainsString("\t", $pasted->text);
        self::assertStringContainsString('line1', $pasted->view());
    }

    public function testRenderedTextCarriesNoSentinels(): void
    {
        $edit = TextEdit::new()->insert("a\u{E000}\u{E001}b");
        self::assertStringNotContainsString("\u{E000}", $edit->view(20));
        self::assertStringNotContainsString("\u{E001}", $edit->view(20));
    }

    public function testValueLineRespectsTheNameColumnBudget(): void
    {
        // Default geometry: the core is exactly 25 + 4 = 29 cells — the
        // historical width — so the M17 fix must not move a byte here.
        $default = OptionRow::render('name', 'value', true, false, true);
        [$line1, $line2] = explode("\n", $default);
        self::assertSame(29, Width::string($line1));
        self::assertSame(29, Width::string($line2));

        // Narrow name column: the value line clips instead of overrunning.
        $narrow = OptionRow::render('n', 'value', true, false, true, nameCol: 10);
        [, $narrowLine2] = explode("\n", $narrow);
        self::assertSame(10, Width::string($narrowLine2));
        self::assertLessThanOrEqual(10, Width::string($narrowLine2));
    }
}

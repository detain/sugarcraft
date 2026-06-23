<?php

declare(strict_types=1);

namespace SugarCraft\Boxer\Tests;

use SugarCraft\Boxer\{Node, SugarBoxer};
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;
use PHPUnit\Framework\TestCase;

/**
 * ANSI-aware content placement: escape sequences ride with the grapheme they
 * style (zero width), placement/truncation is measured by visible columns, and
 * an open style never bleeds past the content region.
 */
final class AnsiPlacementTest extends TestCase
{
    private SugarBoxer $boxer;

    protected function setUp(): void
    {
        $this->boxer = SugarBoxer::new();
    }

    /** Strip every ANSI sequence (CSI/OSC/two-byte) so visible text can be asserted. */
    private static function strip(string $s): string
    {
        return Ansi::strip($s);
    }

    /**
     * Assert no SGR style is left dangling: if the output carries any SGR
     * sequence, the LAST one must be a reset, so colour can't bleed past the
     * content (the reset may sit before trailing pad spaces — that is correct,
     * the pad is intentionally unstyled).
     */
    private function assertNoDanglingStyle(string $out): void
    {
        if (preg_match_all('/\e\[[0-9;]*m/', $out, $m) > 0) {
            $last = end($m[0]);
            $this->assertTrue(
                $last === "\e[0m" || $last === "\e[m",
                'expected the final SGR to be a reset, got: ' . bin2hex($last),
            );
        }
    }

    private function renderLine(string $line, int $w, int $h = 1): string
    {
        return $this->boxer->render(Node::leaf($line)->withBorder(false), $w, $h);
    }

    public function testEscapesDoNotConsumeColumns(): void
    {
        // \e[1m and \e[0m are zero-width: 'AB' + 3 pad = exactly 5 visible cells.
        $out = $this->renderLine("\e[1mAB\e[0m", 5);

        $this->assertSame('AB   ', self::strip($out));
        $this->assertSame(5, Width::string($out));
        $this->assertStringContainsString("\e[1mA", $out); // escape rides with 'A'
    }

    public function testStyledLinePreservesVisibleWidth(): void
    {
        $out = $this->renderLine("\e[7mSEL\e[0m", 10);

        $this->assertSame('SEL       ', self::strip($out));
        $this->assertSame(10, Width::string($out));
    }

    public function testEscapesSurviveInOrder(): void
    {
        $out = $this->renderLine("\e[31mR\e[32mG\e[34mB\e[0m", 8);

        $posR = strpos($out, "\e[31m");
        $posG = strpos($out, "\e[32m");
        $posB = strpos($out, "\e[34m");
        $this->assertNotFalse($posR);
        $this->assertNotFalse($posG);
        $this->assertNotFalse($posB);
        $this->assertTrue($posR < $posG && $posG < $posB, 'colour escapes kept their source order');
        $this->assertSame('RGB     ', self::strip($out));
    }

    public function testFittingReverseSpanKeepsItsReset(): void
    {
        $out = $this->renderLine("\e[7mSEL\e[0m", 6);

        $this->assertStringContainsString("\e[7mS", $out); // reverse opens on first cell
        $this->assertStringContainsString("\e[0m", $out);  // and is reset
        $this->assertSame('SEL   ', self::strip($out));
    }

    public function testClippedReverseSpanIsNotLeaked(): void
    {
        // Open reverse, NO reset, content wider than the region. Truncation must
        // close the span so colour never bleeds into the border / next row.
        $out = $this->renderLine("\e[7mLONGTEXT", 4);

        $this->assertSame('LONG', self::strip($out));   // truncated by visible columns
        $this->assertSame(4, Width::string($out));
        $this->assertNoDanglingStyle($out);     // reset appended → no bleed
    }

    public function testUnbalancedStyleThatFitsIsClosed(): void
    {
        // A style opened but never reset by the source — even though it fits —
        // is terminated so it can't leak past the content region.
        $out = $this->renderLine("\e[1mBOLD", 8);

        $this->assertSame('BOLD    ', self::strip($out));
        $this->assertNoDanglingStyle($out);
    }

    public function testTruncated256ColourSpanIsClosed(): void
    {
        // 38;5;0 is a 256-colour selector (fg = index 0), NOT a reset — the
        // trailing 0 must not be mistaken for one, so the clipped span still
        // gets a reset.
        $out = $this->renderLine("\e[38;5;0mDARKLONG", 4);

        $this->assertSame('DARK', self::strip($out));
        $this->assertNoDanglingStyle($out);
    }

    public function testTruncatedTruecolourSpanIsClosed(): void
    {
        // 38;2;r;g;b truecolour selector — five params consumed, style open.
        $out = $this->renderLine("\e[38;2;10;20;30mRGBLONG", 3);

        $this->assertSame('RGB', self::strip($out));
        $this->assertNoDanglingStyle($out);
    }

    public function testPlainContentIsByteIdenticalToSimplePlacement(): void
    {
        // Regression: unstyled content places one grapheme per cell + padding,
        // exactly as before the ANSI rework.
        $out = $this->renderLine('Hi', 5);

        $this->assertSame('Hi   ', $out);
    }

    public function testWideGraphemesKeepTheirTwoColumns(): void
    {
        // Two wide (CJK) graphemes = 4 visible columns + 2 pad = 6.
        $out = $this->renderLine('世界', 6);

        $this->assertSame(6, Width::string($out));
        $this->assertStringContainsString('世界', $out);
        $this->assertSame('世界  ', self::strip($out));
    }

    public function testWideGraphemeTruncatesOnCellBoundary(): void
    {
        // A wide grapheme that would overflow the last column is dropped whole
        // (no half-glyph), leaving the row exactly the region width.
        $out = $this->renderLine('A世B', 2);

        // 'A' (1) fits; '世' (2) would overflow col 2 → dropped; pad to width 2.
        $this->assertSame('A ', self::strip($out));
        $this->assertSame(2, Width::string($out));
    }

    public function testStyledWordSplitPreservesEscapesAndWidth(): void
    {
        // A single oversized styled word (no spaces) wraps on visible columns
        // and keeps its escapes attached.
        $out = $this->renderLine("\e[1mABCDEFGH\e[0m", 4, 3);
        $rows = explode("\n", $out);

        $this->assertSame('ABCD', rtrim(self::strip($rows[0])));
        $this->assertSame('EFGH', rtrim(self::strip($rows[1])));
        $this->assertStringContainsString("\e[1mA", $rows[0]); // bold rides with 'A'
        $this->assertStringEndsWith("\e[0m", $rows[0]);        // first chunk closed
    }

    public function testPlainWordSplitStillWraps(): void
    {
        $out = $this->renderLine('ABCDEFGH', 4, 3);
        $rows = explode("\n", $out);

        $this->assertSame('ABCD', rtrim(self::strip($rows[0])));
        $this->assertSame('EFGH', rtrim(self::strip($rows[1])));
    }

    public function testOscSequenceIsZeroWidth(): void
    {
        // An OSC sequence (set window title) consumes no columns.
        $out = $this->renderLine("\e]0;title\x07X", 3);

        $this->assertSame('X  ', self::strip($out));
        $this->assertSame(3, Width::string($out));
        $this->assertStringContainsString("\e]0;title\x07", $out);
    }

    public function testOscSequenceWithStringTerminator(): void
    {
        // OSC terminated by ST (ESC \) instead of BEL.
        $out = $this->renderLine("\e]8;;https://x\e\\Y", 3);

        $this->assertSame('Y  ', self::strip($out));
    }

    public function testTwoByteEscapeIsGrouped(): void
    {
        // A non-CSI/OSC escape (ESC + one byte) is consumed as a two-byte
        // sequence and rides with the next grapheme.
        $out = $this->renderLine("\eMX", 3);

        $this->assertSame('X  ', self::strip($out));
        $this->assertStringContainsString("\eM", $out);
    }

    public function testZeroWidthGraphemeFoldsIntoPreviousCell(): void
    {
        // A zero-width space between A and B consumes no column and rides on A's
        // cell, so the visible width is just 'A' + 'B' + padding.
        $out = $this->renderLine("A\u{200b}B", 5);

        $this->assertSame(5, Width::string($out));
        $this->assertStringContainsString("A\u{200b}", $out);
    }

    public function testLeadingZeroWidthGraphemeIsCarried(): void
    {
        // A leading zero-width grapheme (no base yet) is carried onto the first
        // visible cell.
        $out = $this->renderLine("\u{200b}AB", 5);

        $this->assertSame(5, Width::string($out));
        $this->assertStringContainsString("\u{200b}A", $out);
    }

    public function testWideGraphemeWiderThanRegionIsDropped(): void
    {
        // A 2-column grapheme cannot fit a 1-column region — it is dropped whole
        // and the cell is padded, keeping the region exactly 1 visible column.
        $out = $this->renderLine('世', 1);

        $this->assertSame(' ', self::strip($out));
        $this->assertSame(1, Width::string($out));
    }

    public function testCsiNonSgrSequenceIsPreservedZeroWidth(): void
    {
        // A non-SGR CSI sequence (erase-in-line) is grouped zero-width and does
        // not flip the style-open state.
        $out = $this->renderLine("\e[2KX", 3);

        $this->assertSame('X  ', self::strip($out));
        $this->assertStringContainsString("\e[2K", $out);
    }

    public function testEmptyParamsResetClosesStyle(): void
    {
        // ESC[m (no params) is a reset, same as ESC[0m.
        $out = $this->renderLine("\e[7mX\e[m", 3);

        $this->assertSame('X  ', self::strip($out));
        $this->assertNoDanglingStyle($out);
    }

    public function testStrayEscapeBeforeMultibyteDoesNotSplitTheGrapheme(): void
    {
        // A bare ESC (not CSI/OSC) before a multi-byte grapheme must consume only
        // the ESC — never the grapheme's lead byte — so the grapheme stays whole
        // and a following visible char still renders.
        $out = $this->renderLine("\x1b好X", 6);

        $this->assertStringContainsString('好', $out);  // grapheme bytes intact
        $this->assertStringContainsString('X', $out);
        // Sanity: a real 2-byte escape (ASCII final byte) is still consumed whole.
        $out2 = $this->renderLine("\eMY", 4);
        $this->assertStringContainsString("\eM", $out2);
        $this->assertStringContainsString('Y', $out2);
    }

    public function testBoxerFactoryHelpersDelegateToNode(): void
    {
        $b = SugarBoxer::new();
        $this->assertSame(Node::LEAF, $b->leaf('x')->kind);
        $this->assertSame(Node::HORIZONTAL, $b->horizontal($b->leaf('a'), $b->leaf('b'))->kind);
        $this->assertSame(Node::VERTICAL, $b->vertical($b->leaf('a'), $b->leaf('b'))->kind);
        $this->assertSame(Node::NOBORDER, $b->noBorder($b->leaf('a'))->kind);
    }

    public function testStyledLineInsideBorderedBoxDoesNotLeakIntoBorder(): void
    {
        // End-to-end: a reverse-video body line in a bordered leaf. The right
        // border cell must stay an unstyled box character (no colour bleed).
        $layout = Node::leaf("\e[7mhighlighted")->withBorder(true)->withMinWidth(8)->withMinHeight(1);
        $out = $this->boxer->render($layout, 12, 3);

        // The reset appears before the row's right border glyph on the body row.
        $this->assertStringContainsString("\e[0m", $out);
        $body = explode("\n", $out)[1] ?? '';
        $this->assertStringEndsWith('│', self::strip($body)); // right border intact
    }
}

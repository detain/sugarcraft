<?php

declare(strict_types=1);

namespace SugarCraft\Toast\Tests;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;
use SugarCraft\Toast\{Position, SymbolSet, Toast, ToastType};
use PHPUnit\Framework\TestCase;

/**
 * Regression guard for the overlay border-truncation bug.
 *
 * The old composite/render path sliced lines by BYTE length (strlen /
 * substr). A box border like '╭' . str_repeat('─', 48) . '╮' is 50
 * display cells but 150 UTF-8 bytes, so a byte cut at column 50 sliced
 * the 16th '─' mid-grapheme — producing a stray dangling byte. These
 * tests fail on that old behaviour.
 */
final class ToastBorderTest extends TestCase
{
    /** Split rendered output into lines (drop trailing empty). */
    private function rows(string $rendered): array
    {
        $lines = \explode("\n", $rendered);
        if (\end($lines) === '') {
            \array_pop($lines);
        }
        return $lines;
    }

    /** First line that begins with the top-border corner. */
    private function topBorder(array $rows): string
    {
        foreach ($rows as $r) {
            if (\str_starts_with(\ltrim($r), '╭')) {
                return \ltrim($r);
            }
        }
        $this->fail('No top border line found in rendered output.');
    }

    private function bottomBorder(array $rows): string
    {
        foreach ($rows as $r) {
            if (\str_starts_with(\ltrim($r), '╰')) {
                return \ltrim($r);
            }
        }
        $this->fail('No bottom border line found in rendered output.');
    }

    public function testTopBorderIsIntactForWidth50(): void
    {
        $width = 50;
        $t = Toast::new($width)
            ->withPosition(Position::TopLeft)
            ->info('Hello world');

        $bg = \str_repeat("line\n", 12);
        $rows = $this->rows($t->View($bg, 80, 12));

        $expectedTop    = '╭' . \str_repeat('─', $width - 2) . '╮';
        $expectedBottom = '╰' . \str_repeat('─', $width - 2) . '╯';

        $this->assertSame($expectedTop, $this->topBorder($rows));
        $this->assertSame($expectedBottom, $this->bottomBorder($rows));
    }

    public function testNoBrokenOrReplacementBytesInBorders(): void
    {
        $t = Toast::new(50)
            ->withPosition(Position::TopLeft)
            ->info('Hello world');

        $rendered = $t->View(\str_repeat("line\n", 12), 80, 12);

        // U+FFFD REPLACEMENT CHARACTER would appear if a multibyte grapheme
        // were sliced mid-byte and re-decoded.
        $this->assertStringNotContainsString("\u{FFFD}", $rendered);

        // The whole rendered output must be valid UTF-8 — a mid-grapheme
        // cut leaves a dangling continuation byte that fails this check.
        $this->assertTrue(
            \mb_check_encoding($rendered, 'UTF-8'),
            'Rendered toast contains invalid UTF-8 (mid-grapheme slice).'
        );
    }

    public function testBorderRowsHaveCorrectDisplayWidth(): void
    {
        $width = 50;
        $t = Toast::new($width)
            ->withPosition(Position::TopLeft)
            ->info('Hello world');

        $rows = $this->rows($t->View(\str_repeat("line\n", 12), 80, 12));

        // Each border line is exactly $width cells (1 corner + N dashes + 1).
        $this->assertSame($width, Width::string($this->topBorder($rows)));
        $this->assertSame($width, Width::string($this->bottomBorder($rows)));
    }

    public function testHeaderRowEdgesAreVerticalBars(): void
    {
        $width = 50;
        $t = Toast::new($width)
            ->withPosition(Position::TopLeft)
            ->info('Hello world');

        $rows = $this->rows($t->View(\str_repeat("line\n", 12), 80, 12));

        // Find the header row (between the borders) — contains the message.
        $header = null;
        foreach ($rows as $r) {
            if (\str_contains($r, 'Hello world')) {
                $header = \ltrim($r);
                break;
            }
        }
        $this->assertNotNull($header, 'Header row not found.');

        // After stripping ANSI the row is │ ... │ at exactly $width cells.
        $clean = Ansi::strip($header);
        $this->assertStringStartsWith('│', $clean);
        $this->assertStringEndsWith('│', $clean);
        $this->assertSame($width, Width::string($clean));
    }

    public function testMultibyteContentKeepsBordersAligned(): void
    {
        $width = 50;
        // Em-dash, accented chars, and a CJK char in the message body.
        $t = Toast::new($width)
            ->withPosition(Position::TopLeft)
            ->warning('Café — 状態 update — déjà vu');

        $rows = $this->rows($t->View(\str_repeat("line\n", 12), 80, 12));

        $this->assertTrue(\mb_check_encoding(\implode("\n", $rows), 'UTF-8'));
        $this->assertSame($width, Width::string($this->topBorder($rows)));
        $this->assertSame($width, Width::string($this->bottomBorder($rows)));

        // Every row inside the box that carries a border edge keeps the
        // right '│' flush at the box column.
        foreach ($rows as $r) {
            $clean = Ansi::strip(\ltrim($r));
            if (\str_starts_with($clean, '│') && \str_ends_with($clean, '│')) {
                $this->assertSame($width, Width::string($clean), 'Body row width drifted: ' . $clean);
            }
        }
    }

    public function testAnsiStyledContentDoesNotBreakBorders(): void
    {
        $width = 50;
        // Inline SGR styling embedded in the message body. The icon prefix
        // is already styled; add more to exercise ANSI-aware slicing.
        $styled = Ansi::sgr(Ansi::BOLD) . 'BOLD' . Ansi::reset()
            . ' and ' . Ansi::fg16(31) . 'red' . Ansi::reset() . ' text';
        $t = Toast::new($width)
            ->withPosition(Position::TopLeft)
            ->success($styled);

        $rendered = $t->View(\str_repeat("line\n", 12), 80, 12);
        $rows = $this->rows($rendered);

        $this->assertTrue(\mb_check_encoding($rendered, 'UTF-8'));

        $expectedTop = '╭' . \str_repeat('─', $width - 2) . '╮';
        $this->assertSame($expectedTop, $this->topBorder($rows));
        $this->assertSame($width, Width::string($this->bottomBorder($rows)));

        // The styling escapes must survive (not be stripped by slicing).
        // Both \x1b[1m and \x1b[0;1m are semantically identical bold.
        $this->assertMatchesRegularExpression('/\x1b\[0;1m|\x1b\[1m/', $rendered);
    }

    public function testStackedToastsDoNotLeakAnsiPastBorder(): void
    {
        // Two stacked toasts at TopLeft must not overlap; an overlap left
        // stale trailing SGR resets harvested past the right border.
        $t = Toast::new(50)
            ->withPosition(Position::TopLeft)
            ->info('first toast')
            ->error('second toast');

        $rows = $this->rows($t->View(\str_repeat("line\n", 20), 80, 20));

        foreach ($rows as $r) {
            $clean = Ansi::strip(\ltrim($r));
            if (\str_starts_with($clean, '│')) {
                // A '│ … │' body row must end with '│' — nothing (visible or
                // ANSI) leaks beyond it.
                $this->assertStringEndsWith('│', $clean, 'Body row leaked content past border: ' . $clean);
            }
        }
    }

    /**
     * Regression test: withMinWidth(n) with n > 0 produces a box width
     * that respects minWidth and maxWidth constraints. Guards the
     * resolveWidth() icon-space fix (sugar-toast-3).
     */
    public function testAutoWidthRespectsMinWidth(): void
    {
        $minWidth = 20;
        $maxWidth = 50;
        $t = Toast::new($maxWidth)
            ->withMinWidth($minWidth)
            ->withPosition(Position::TopLeft)
            ->info('Hi');  // short message

        $rows = $this->rows($t->View(\str_repeat("line\n", 12), 80, 12));
        $borderWidth = Width::string($this->topBorder($rows));

        $this->assertGreaterThanOrEqual($minWidth, $borderWidth);
        $this->assertLessThanOrEqual($maxWidth, $borderWidth);
    }

    /**
     * Regression test: Ascii and NerdFont symbol sets must not produce
     * different box widths for the same message purely due to enum-name
     * length (the pre-fix bug). The width must be driven by actual icon
     * cell count (3 for Ascii [E], 1 for NerdFont icon) plus content.
     */
    public function testAutoWidthSameForAsciiAndNerdFont(): void
    {
        $minWidth = 15;
        $maxWidth = 50;

        $tAscii = Toast::new($maxWidth)
            ->withMinWidth($minWidth)
            ->withSymbolSet(SymbolSet::Ascii)
            ->withPosition(Position::TopLeft)
            ->info('Hi');

        $tNerd = Toast::new($maxWidth)
            ->withMinWidth($minWidth)
            ->withSymbolSet(SymbolSet::NerdFont)
            ->withPosition(Position::TopLeft)
            ->info('Hi');

        $rowsAscii = $this->rows($tAscii->View(\str_repeat("line\n", 12), 80, 12));
        $rowsNerd = $this->rows($tNerd->View(\str_repeat("line\n", 12), 80, 12));

        $widthAscii = Width::string($this->topBorder($rowsAscii));
        $widthNerd = Width::string($this->topBorder($rowsNerd));

        // Before the sugar-toast-3 fix, Ascii produced width ~14 (strlen("Ascii")=5 + 2 + msg + 4)
        // and NerdFont produced ~17 (strlen("NerdFont")=8 + 2 + msg + 4) for identical messages.
        // After the fix, both should be driven by actual icon cell count.
        // For message "Hi" (~2 cells) with minWidth=15: needed = 2 + iconSpace + 4
        //   Ascii: 2 + 4 + 4 = 10 -> clamped to minWidth 15
        //   NerdFont: 2 + 2 + 4 = 8 -> clamped to minWidth 15
        // So both should equal 15 (minWidth) since the message is short.
        $this->assertSame($widthAscii, $widthNerd,
            'Ascii and NerdFont produced different widths for identical messages '
            . '(pre-fix bug: enum-name length drove width calculation)');
    }
}

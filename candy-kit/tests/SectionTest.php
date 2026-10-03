<?php

declare(strict_types=1);

namespace SugarCraft\Kit\Tests;

use SugarCraft\Core\Util\Width;
use SugarCraft\Kit\Section;
use SugarCraft\Kit\Theme;
use PHPUnit\Framework\TestCase;

final class SectionTest extends TestCase
{
    public function testHeaderFillsToWidth(): void
    {
        $out = Section::header('SETUP', Theme::plain(), leftPad: 2, width: 20);
        $this->assertSame(20, Width::string($out));
        $this->assertStringContainsString('SETUP', $out);
        $this->assertStringStartsWith('──', $out);
    }

    public function testHeaderWithoutWidthEndsAfterTrailingRune(): void
    {
        $out = Section::header('A', Theme::plain(), leftPad: 1, width: null);
        $this->assertSame('─ A ─', $out);
    }

    public function testRule(): void
    {
        $this->assertSame(str_repeat('─', 10), Section::rule(Theme::plain(), 10));
    }

    public function testCustomRune(): void
    {
        $out = Section::header('X', Theme::plain(), leftPad: 2, width: 8, rune: '=');
        $this->assertStringContainsString('==', $out);
        $this->assertSame(8, Width::string($out));
    }

    /** Multi-cell runes must never overshoot the requested width. */
    public function testMultiCellRuneNeverOvershoots(): void
    {
        // '──' is a 2-cell rune; leftPad=2 means 2 runes = 4 cells.
        // With label ' X ' (3 cells) + fill, total is 19 (not 20 — the
        // 2-cell rune cannot fill the last slot exactly). The important
        // guarantee is that it never EXCEEDS 20.
        $out = Section::header('X', Theme::plain(), leftPad: 2, width: 20, rune: '──');
        $this->assertLessThanOrEqual(20, Width::string($out));
        // Verify exact width for a single-cell rune case (leftPad=2, rune='─')
        $out2 = Section::header('X', Theme::plain(), leftPad: 2, width: 20, rune: '─');
        $this->assertSame(20, Width::string($out2));
    }

    /** rule() applies the theme's muted style (emits SGR for non-plain themes). */
    public function testRuleAppliesTheme(): void
    {
        $out = Section::rule(Theme::ansi(), 10);
        // Theme::ansi()->muted is Style::new()->faint() which emits SGR 2 (faint).
        $this->assertStringContainsString("\x1b[2m", $out);
    }

    /**
     * Security: the caller label is interpolated raw into the header. Under the
     * plain theme (no SGR of its own) any ESC / BEL in the output must have
     * come from the label — assert they are neutralized for both header() and
     * subHeader(). (Revert the SafeText routing → leaks → fails.)
     */
    public function testLabelEscapeAndControlBytesNeutralized(): void
    {
        $evil = "SET\x1b[2Jx\x1b]0;t\x07UP";
        foreach ([
            Section::header($evil, Theme::plain(), width: 40),
            Section::subHeader($evil, Theme::plain(), width: 40),
        ] as $out) {
            $this->assertStringNotContainsString("\x1b", $out, 'ESC injection must be stripped');
            $this->assertStringNotContainsString("\x07", $out, 'BEL must be stripped');
            $this->assertStringContainsString('SETxUP', $out);
        }
    }

    /** Clean ASCII label renders byte-for-byte identically (no regression). */
    public function testCleanLabelUnchanged(): void
    {
        $this->assertSame('─ A ─', Section::header('A', Theme::plain(), leftPad: 1, width: null));
    }

    public function testSubHeaderWithEmptyLabelRendersOnlyDivider(): void
    {
        $out = Section::subHeader('', Theme::plain(), indent: 4, width: 20);
        // Empty label means no styled text, just indent + trailing rune fill.
        $this->assertStringStartsWith('    ', $out);
        $this->assertSame(20, Width::string($out));
    }

    public function testSubHeaderWithExplicitWidth(): void
    {
        $out = Section::subHeader('Opts', Theme::plain(), indent: 2, width: 30, rune: '·');
        $this->assertSame(30, Width::string($out));
        $this->assertStringContainsString('Opts', $out);
    }

    public function testSubHeaderDefaultIndentIsFour(): void
    {
        $out = Section::subHeader('x', Theme::plain());
        // Default indent=4 means 4 leading spaces.
        $this->assertStringStartsWith('    ', $out);
    }

    public function testSubHeaderNullWidthEndsAfterTrailingRune(): void
    {
        $out = Section::subHeader('X', Theme::plain(), indent: 2, width: null);
        // null width: head + one rune, no fill calculation.
        $this->assertStringStartsWith('  ', $out);
    }

    public function testSubHeaderWithAnsiThemeEmitsStyle(): void
    {
        $out = Section::subHeader('Opts', Theme::ansi(), indent: 2, width: 20);
        // ansi accent style emits SGR on the label.
        $this->assertStringContainsString("\x1b[", $out);
    }

    public function testHeaderEmptyLabelRendersWithoutStyling(): void
    {
        $out = Section::header('', Theme::plain(), leftPad: 2, width: 20);
        // Empty label → no styled text, just pad runes + fill.
        $this->assertStringStartsWith('──', $out);
        $this->assertSame(20, Width::string($out));
    }

    public function testHeaderWithZeroLeftPad(): void
    {
        $out = Section::header('Hi', Theme::plain(), leftPad: 0, width: 20);
        $this->assertSame(20, Width::string($out));
        $this->assertStringContainsString('Hi', $out);
    }

    public function testRuleWithNullWidthProducesMinimumTwoCells(): void
    {
        $out = Section::rule(Theme::plain(), width: null);
        // When width is null, rule() uses min 2 cells of rune.
        $this->assertGreaterThanOrEqual(2, Width::string($out));
    }

    public function testRuleDefaultWidthIs80(): void
    {
        $out = Section::rule(Theme::plain());
        $this->assertSame(80, Width::string($out));
    }

    /**
     * $width is a hard cap: a label longer than the room left is cut with an
     * ellipsis instead of pushing the line past the requested width (it used
     * to return 44 cells for width 20, forcing a terminal wrap).
     */
    public function testHeaderTruncatesOverlongLabelToWidth(): void
    {
        $out = Section::header('A VERY LONG SECTION LABEL THAT OVERFLOWS', Theme::plain(), 2, 20);
        $this->assertSame(20, Width::string($out));
        // 2 lead runes + space + 15-cell label cut + … + space = 20.
        $this->assertSame('── A VERY LONG SEC… ', $out);
    }

    public function testSubHeaderTruncatesOverlongLabelToWidth(): void
    {
        $out = Section::subHeader('A VERY LONG SECTION LABEL', Theme::plain(), 4, 10);
        $this->assertSame(10, Width::string($out));
        // 4-space indent + space + 3-cell label cut + … + space = 10.
        $this->assertSame('     A V… ', $out);
    }

    /** The cut is made on the plain label, so styled output keeps whole SGR runs. */
    public function testTruncatedLabelStaysWellFormedUnderAnsiTheme(): void
    {
        $out = Section::header('A VERY LONG SECTION LABEL', Theme::ansi(), 2, 12);
        $this->assertSame(12, Width::string($out));
        $this->assertStringContainsString("\x1b[0m", $out, 'the label style is closed');
        $this->assertStringContainsString('…', $out);
    }

    /** No room even for the ellipsis: the label is dropped, the line still fits. */
    public function testLabelDroppedWhenNotEvenEllipsisFits(): void
    {
        $this->assertSame('───', Section::header('LABEL', Theme::plain(), leftPad: 2, width: 3));
        $this->assertSame('   ··', Section::subHeader('LABEL', Theme::plain(), indent: 3, width: 5));
    }

    /** A lead (runes / indent) wider than the whole line is cut to the width. */
    public function testLeadWiderThanWidthIsCut(): void
    {
        $this->assertSame('───', Section::header('X', Theme::plain(), leftPad: 10, width: 3));
        $this->assertSame('  ', Section::subHeader('X', Theme::plain(), indent: 8, width: 2));
        $this->assertSame('', Section::header('X', Theme::plain(), width: 0));
        $this->assertSame('', Section::header('X', Theme::plain(), width: -5));
    }

    /** Wide (CJK) labels are cut by display cells, never past the width. */
    public function testWideLabelTruncationNeverOvershoots(): void
    {
        foreach (range(4, 14) as $w) {
            $out = Section::header('漢字漢字漢字漢字', Theme::plain(), 2, $w);
            $this->assertLessThanOrEqual($w, Width::string($out), "width {$w}");
        }
    }

    /** rule() length contract as documented: whole runes in max(1, $width) cells. */
    public function testRuleLengthContract(): void
    {
        $this->assertSame('─', Section::rule(Theme::plain(), 0));
        $this->assertSame('─', Section::rule(Theme::plain(), -3));
        $this->assertSame('', Section::rule(Theme::plain(), 1, '漢'));
        $this->assertSame('──', Section::rule(Theme::plain(), null));
        $this->assertSame('漢', Section::rule(Theme::plain(), null, '漢'));
    }
}

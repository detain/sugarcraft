<?php

declare(strict_types=1);

namespace SugarCraft\Cli\Tests;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;
use SugarCraft\Cli\Section;
use SugarCraft\Cli\Theme;
use SugarCraft\Cli\Tests\Support\SandboxTerminalColumns;
use PHPUnit\Framework\TestCase;

final class SectionTest extends TestCase
{
    use SandboxTerminalColumns;

    protected function setUp(): void
    {
        // Several pins below omit $width and assert the fallback geometry;
        // hide any terminal width the runner advertises so they stay honest.
        $this->sandboxTerminalColumns();
    }

    protected function tearDown(): void
    {
        $this->restoreTerminalColumns();
    }

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
        // '──' is a 2-cell rune; leftPad=2 is a CELL budget, so the lead is
        // one whole rune (2 cells) — never two runes/4 cells (finding 010).
        // With label ' X ' (3 cells) + fill, the total never EXCEEDS 20; the
        // exact landing can leave a partial cell that the fill cannot take.
        $out = Section::header('X', Theme::plain(), leftPad: 2, width: 20, rune: '──');
        $this->assertLessThanOrEqual(20, Width::string($out));
        $this->assertSame('── ', mb_substr($out, 0, 3));   // 2-cell lead (2 codepoints), then label pad
        // Verify exact width for a single-cell rune case (leftPad=2, rune='─')
        $out2 = Section::header('X', Theme::plain(), leftPad: 2, width: 20, rune: '─');
        $this->assertSame(20, Width::string($out2));
    }

    /** header()'s $leftPad and subHeader()'s $indent are the same CELL unit (010). */
    public function testHeaderLeadAndSubHeaderIndentAgreeInCells(): void
    {
        $h = Section::header('S', Theme::plain(), leftPad: 4, width: 40, rune: '──');
        $s = Section::subHeader('S', Theme::plain(), indent: 4, width: 40, rune: '──');
        // 4 nominal cells each: header spends two whole 2-cell runes then the
        // label pad (a third rune would overshoot the budget), subHeader four
        // spaces. Rune-count semantics (leftPad=4 -> 4 runes = 8 cells) die here.
        $this->assertSame('──── ', mb_substr($h, 0, 5));
        $this->assertSame('    ', mb_substr($s, 0, 4));
        $this->assertSame(Width::string(mb_substr($h, 0, 4)), Width::string(mb_substr($s, 0, 4)));
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

    /** With no terminal width advertised, the rule falls back to 80 cells. */
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
    }

    /**
     * A width below 1 is an authoring error and throws rather than silently
     * rendering an empty line. Formerly Section clamped these to 0 (an empty
     * result, pinned here as `''` before width 0 and -5); harmonized on throw
     * to agree with HelpText::assertWidth(), so the two presenters now fail
     * identically on the same bad input.
     */
    public function testWidthBelowOneThrowsInsteadOfCollapsingToEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Section::header('X', Theme::plain(), width: 0);
    }

    public function testWidthBelowOneThrowsForSubHeaderAndRule(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Section::subHeader('X', Theme::plain(), width: 0);
    }

    /** Every entry point guards before doing any work, including rule(). */
    public function testRuleWidthBelowOneThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Section::rule(Theme::plain(), -3);
    }

    /** The thrown message names the offending value, per the fail-loud law. */
    public function testWidthErrorNamesTheBadValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('got -5.');
        Section::header('X', Theme::plain(), width: -5);
    }

    /** Wide (CJK) labels are cut by display cells, never past the width. */
    public function testWideLabelTruncationNeverOvershoots(): void
    {
        foreach (range(4, 14) as $w) {
            $out = Section::header('漢字漢字漢字漢字', Theme::plain(), 2, $w);
            $this->assertLessThanOrEqual($w, Width::string($out), "width {$w}");
        }
    }

    /** rule() length contract as documented: whole runes in the width budget. */
    public function testRuleLengthContract(): void
    {
        // Widths below 1 now throw (testRuleWidthBelowOneThrows) instead of
        // clamping to a single rune, so this contract starts at width 1.
        // A rune that cannot fit the budget renders as one truncated cell
        // (the `…` marker), not as a vanished rule (finding 031).
        $this->assertSame('…', Section::rule(Theme::plain(), 1, '漢'));
        $this->assertSame(1, Width::string(Section::rule(Theme::plain(), 1, '漢')));
        $this->assertSame('──', Section::rule(Theme::plain(), null));
        $this->assertSame('漢', Section::rule(Theme::plain(), null, '漢'));
    }

    /**
     * Security: the fill rune is caller-supplied display text and passes
     * through SafeText at entry — injected SGR / OSC never reach the line
     * (the fill would otherwise repeat the payload once per cell).
     */
    public function testRuneInjectionNeutralized(): void
    {
        $out = Section::header('X', Theme::plain(), leftPad: 2, width: 12, rune: "\x1b[1m=");
        $this->assertStringNotContainsString("\x1b", $out, 'ESC must not survive the rune path');
        $this->assertStringContainsString('=', $out);

        $rule = Section::rule(Theme::plain(), width: 4, rune: "\x1b]0;a\x07-");
        $this->assertStringNotContainsString("\x1b", $rule);
        $this->assertStringNotContainsString("\x07", $rule);
        $this->assertSame('----', $rule);
    }

    /** A rune that sanitizes to '' is a fail-loud authoring error at all three sites (finding 031). */
    public function testEmptyRuneThrowsAtEveryEntry(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Section::header(): $rune sanitized to an empty string');
        Section::header('A', Theme::plain(), width: 10, rune: '');
    }

    public function testEmptyRuneThrowsInRule(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Section::rule(): $rune sanitized to an empty string');
        Section::rule(Theme::plain(), 8, '');
    }

    public function testEmptyRuneThrowsInSubHeader(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Section::subHeader(): $rune sanitized to an empty string');
        Section::subHeader('A', Theme::plain(), width: 10, rune: '');
    }

    /** Escape-only input is empty after SafeText — same door, not a silent fallback. */
    public function testEscapeOnlyRuneThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Section::rule(): $rune sanitized to an empty string');
        Section::rule(Theme::plain(), 8, "\x1b[31m");
    }
    /**
     * Finding 033: header()/subHeader() lead and fill runs carry the theme's
     * muted style exactly as rule() does, so all three surfaces read as one
     * visual family. Cell layout is untouched by the styling (SGR is
     * zero-width), which the strip-equality arms pin.
     */
    public function testHeaderAndSubHeaderFillRunsAreMutedStyled(): void
    {
        $header    = Section::header('Features', Theme::ansi(), width: 20);
        $subHeader = Section::subHeader('Opts', Theme::ansi(), width: 20);
        $rule      = Section::rule(Theme::ansi(), 20);

        // Theme::ansi()->muted emits SGR 2 (faint) — same opening as rule().
        $this->assertStringStartsWith("\x1b[2m", $header);
        $this->assertStringStartsWith("\x1b[2m", $subHeader);
        $this->assertStringStartsWith("\x1b[2m", $rule);

        // Stripped of every SGR run, the ansi layout is the plain bytes.
        $this->assertSame(Section::header('Features', Theme::plain(), width: 20), Ansi::strip($header));
        $this->assertSame(Section::subHeader('Opts', Theme::plain(), width: 20), Ansi::strip($subHeader));

        // Null-width paths: the lead and single trailing rune are muted too.
        $nullHeader = Section::header('A', Theme::ansi(), leftPad: 1, width: null);
        $this->assertStringStartsWith("\x1b[2m─\x1b[0m", $nullHeader);
        $this->assertSame('─ A ─', Ansi::strip($nullHeader));
        $this->assertStringEndsWith("\x1b[2m─\x1b[0m", $nullHeader);
    }
}

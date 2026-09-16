<?php

declare(strict_types=1);

namespace SugarCraft\Spark\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Spark\Inspector;

final class UnderlineStylesTest extends TestCase
{
    public function testUnderlineSingle(): void
    {
        $seg = Inspector::parse("\x1b[4:1m")[0];
        $this->assertStringContainsString('underline single', $seg->describe());
    }

    public function testUnderlineDouble(): void
    {
        $seg = Inspector::parse("\x1b[4:2m")[0];
        $this->assertStringContainsString('underline double', $seg->describe());
    }

    public function testUnderlineCurly(): void
    {
        $seg = Inspector::parse("\x1b[4:3m")[0];
        $this->assertStringContainsString('underline curly', $seg->describe());
    }

    public function testUnderlineDotted(): void
    {
        $seg = Inspector::parse("\x1b[4:4m")[0];
        $this->assertStringContainsString('underline dotted', $seg->describe());
    }

    public function testUnderlineDashed(): void
    {
        $seg = Inspector::parse("\x1b[4:5m")[0];
        $this->assertStringContainsString('underline dashed', $seg->describe());
    }

    public function testUnderlineStyleUnknownSub(): void
    {
        // 4:6 is not a defined style, should fall back gracefully.
        $seg = Inspector::parse("\x1b[4:6m")[0];
        $this->assertStringContainsString('underline style 6', $seg->describe());
    }

    public function testUnderlineStyleCompoundWithBold(): void
    {
        // Compound: bold + underline double.
        $seg = Inspector::parse("\x1b[1;4:2m")[0];
        $desc = $seg->describe();
        $this->assertStringContainsString('bold', $desc);
        $this->assertStringContainsString('underline double', $desc);
    }

    public function testUnderlineStyleCompoundWithForeground(): void
    {
        // Compound: red + underline single.
        $seg = Inspector::parse("\x1b[31;4:1m")[0];
        $desc = $seg->describe();
        $this->assertStringContainsString('foreground red', $desc);
        $this->assertStringContainsString('underline single', $desc);
    }

    public function testUnderlineStyleCompoundAllThree(): void
    {
        // Compound: bold + red + underline dashed.
        $seg = Inspector::parse("\x1b[1;31;4:5m")[0];
        $desc = $seg->describe();
        $this->assertStringContainsString('bold', $desc);
        $this->assertStringContainsString('foreground red', $desc);
        $this->assertStringContainsString('underline dashed', $desc);
    }

    public function testUnderlineStyleUnknownCompoundStillParsesRest(): void
    {
        // Unknown underline sub-style 99 via the colon form (4:99) works at the
        // describeCsi level (bypasses the Parser param-splitting which loses the
        // colon separator for sub-params).
        $desc = Inspector::describeCsi('1;4:99', 'm');
        $this->assertStringContainsString('bold', $desc);
        $this->assertStringContainsString('underline style 99', $desc);
    }

    /**
     * The semicolon form 4;NN is two separate SGR params, not a combined
     * underline style.  After removing the heuristic, 4;99 decodes as
     * underline + SGR 99 (no special style label).
     */
    public function testSemicolonFormUnderlineThenSgrCodeIsNotMisdecoded(): void
    {
        $desc = Inspector::describeCsi('4;99', 'm');
        // Must be separate underline + SGR 99, NOT "underline style 99".
        $this->assertStringContainsString('underline', $desc);
        $this->assertStringContainsString('SGR 99', $desc);
        $this->assertStringNotContainsString('underline style', $desc);
    }

    /**
     * Regression: 4;53 (underline + SGR 53=overline) must NOT be misdecoded
     * as "underline style 53".  The semicolon form is two separate SGR params.
     */
    public function testUnderlineThenOverlineNotMisdecoded(): void
    {
        $desc = Inspector::describeCsi('4;53', 'm');
        $this->assertStringContainsString('underline', $desc);
        // SGR 53 is "overline" — the heuristic must not claim "underline style 53".
        $this->assertStringNotContainsString('underline style 53', $desc);
        // The 53 should still appear as an SGR code.
        $this->assertStringContainsString('SGR 53', $desc);
    }

    /**
     * Regression: 4;38;5;200 (underline + 256-color fg) must NOT be misdecoded
     * as "underline style 38, blink, SGR 200".  The 38;5;200 is a single
     * foreground 256-color unit.
     */
    public function testUnderlineThen256ColorFg(): void
    {
        $desc = Inspector::describeCsi('4;38;5;200', 'm');
        $this->assertStringContainsString('underline', $desc);
        $this->assertStringContainsString('foreground 256-color 200', $desc);
        // Must NOT claim "underline style 38" or "blink".
        $this->assertStringNotContainsString('underline style 38', $desc);
        $this->assertStringNotContainsString('blink', $desc);
    }

    /**
     * Step 10.16: a colon style must never be borrowed across the semicolon.
     * An earlier revision peeked at the NEXT parameter group for a `4:N` and
     * cast it with (int), so `4;4:3` re-read the group `4:3` as style 4 and
     * reported one effect "underline dotted" where ECMA-48 has two: a plain
     * underline and an underline curly.
     */
    public function testSemicolonBeforeColonStyleYieldsTwoEffectsNotOne(): void
    {
        $desc = Inspector::describeCsi('4;4:3', 'm');
        $this->assertStringContainsString('underline, underline curly', $desc);
        $this->assertStringNotContainsString('dotted', $desc);
    }

    /**
     * `4:0` is "underline off" — ECMA-48 names the zero sub-parameter, and
     * the candy-vt UnderlineStyle enum (step 07.05) carries it as None, so
     * the report must not fall through to the numbered-label fallback.
     */
    public function testZeroStyleTurnsUnderlineOff(): void
    {
        $this->assertSame('SGR no underline', Inspector::describeCsi('4:0', 'm'));
    }

    /**
     * A truncated colon colour belongs to the 38/48 colour machinery, not to
     * the colon-interception path an earlier revision short-circuited — that
     * one double-prefixed every non-underline `N:M` group as "SGR SGR 38:5".
     */
    public function testTruncatedColonColourIsNamedByTheColourBranch(): void
    {
        $desc = Inspector::describeCsi('38:5', 'm');
        $this->assertStringContainsString('foreground truncated 256-color', $desc);
        $this->assertStringNotContainsString('SGR SGR', $desc);
    }
}
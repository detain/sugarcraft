<?php

declare(strict_types=1);

namespace SugarCraft\Kit\Tests;

use SugarCraft\Kit\Stage;
use SugarCraft\Kit\Theme;
use PHPUnit\Framework\TestCase;

final class StageTest extends TestCase
{
    public function testStepRendersGlyphCountAndMessage(): void
    {
        $out = Stage::step(2, 5, 'building', Theme::plain());
        $this->assertSame('▸ 2/5 building', $out);
    }

    public function testStepWithoutTotalOmitsSlash(): void
    {
        $out = Stage::step(7, 0, 'cleanup', Theme::plain());
        $this->assertSame('▸ 7 cleanup', $out);
    }

    public function testCustomGlyph(): void
    {
        $out = Stage::step(1, 1, 'go', Theme::plain(), Stage::GLYPH_HASH);
        $this->assertSame('# 1/1 go', $out);
    }

    public function testSubStepTeeAndCorner(): void
    {
        $tee = Stage::subStep('inner', Theme::plain(), isLast: false, indent: 2);
        $end = Stage::subStep('done',  Theme::plain(), isLast: true,  indent: 2);
        $this->assertStringStartsWith('  ├─ inner', $tee);
        $this->assertStringStartsWith('  └─ done',  $end);
    }

    public function testThemeAppliesAccentToGlyph(): void
    {
        $out = Stage::step(1, 2, 'x', Theme::ansi());
        // ANSI accent style emits SGR; raw string contains ESC sequence.
        $this->assertStringContainsString("\x1b[", $out);
    }

    /** GLYPH_BULLET is currently the only un-exercised constant — close that gap. */
    public function testBulletGlyph(): void
    {
        $out = Stage::step(1, 0, 'init', Theme::plain(), Stage::GLYPH_BULLET);
        $this->assertStringContainsString('•', $out);
        $this->assertStringContainsString('init', $out);
    }

    public function testSubStepWithProgressRendersBarAndPercent(): void
    {
        $out = Stage::subStepWithProgress('upload', 4, 10, Theme::plain(), isLast: false, indent: 2);
        // 40% → 4 filled / 6 empty segments, right-aligned percentage.
        $this->assertSame('  ├─ upload ████░░░░░░  40%', $out);
    }

    /**
     * Regression: the indeterminate path (`total = 0`) previously multiplied
     * microtime(false) — a STRING like "0.12 1700000000" — which raises a
     * "non-numeric value encountered" warning. Under failOnWarning=true that
     * warning fails the suite. Drive that exact path and assert a clean render.
     */
    public function testSubStepWithProgressIndeterminateSpinnerHasNoWarning(): void
    {
        $out = Stage::subStepWithProgress('syncing', 0, 0, Theme::plain(), isLast: false, indent: 2);

        $this->assertStringStartsWith('  ├─ syncing ', $out);

        $spinnerFrames = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];
        $frame = mb_substr($out, -1);
        $this->assertContains($frame, $spinnerFrames, 'trailing glyph must be a spinner frame');
    }

    /**
     * Security: the caller message is interpolated raw into stage output.
     * Under the plain theme (no SGR of its own) any ESC / BEL in the rendered
     * line must have come from the message — assert they are neutralized in
     * all three render paths. (Revert the SafeText routing → leaks → fails.)
     */
    public function testMessageEscapeAndControlBytesNeutralized(): void
    {
        $evil = "run\x1b[2Jx\x1b]0;t\x07end";
        foreach ([
            Stage::step(1, 2, $evil, Theme::plain()),
            Stage::subStep($evil, Theme::plain()),
            Stage::subStepWithProgress($evil, 3, 10, Theme::plain()),
        ] as $out) {
            $this->assertStringNotContainsString("\x1b", $out, 'ESC injection must be stripped');
            $this->assertStringNotContainsString("\x07", $out, 'BEL must be stripped');
            $this->assertStringContainsString('runx', $out);
        }
    }

    /** Clean ASCII messages render byte-for-byte identically (no regression). */
    public function testCleanMessageUnchanged(): void
    {
        $this->assertSame('▸ 2/5 building', Stage::step(2, 5, 'building', Theme::plain()));
    }
}

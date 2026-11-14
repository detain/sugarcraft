<?php

declare(strict_types=1);

namespace SugarCraft\Cli\Tests;

use SugarCraft\Cli\Stage;
use SugarCraft\Cli\Theme;
use SugarCraft\Cli\Tests\Support\SandboxTerminalColumns;
use SugarCraft\Core\Util\Width;
use PHPUnit\Framework\TestCase;

final class StageTest extends TestCase
{
    use SandboxTerminalColumns;

    protected function setUp(): void
    {
        // Omitted $width resolves AutoWidth::Auto — hide any terminal width
        // the runner advertises so the 80-fallback pins stay honest.
        $this->sandboxTerminalColumns();
    }

    protected function tearDown(): void
    {
        $this->restoreTerminalColumns();
    }

    public function testStepRendersGlyphCountAndMessage(): void
    {
        $out = Stage::step('building', 2, 5, Theme::plain());
        $this->assertSame('▸ 2/5 building', $out);
    }

    public function testStepWithoutTotalOmitsSlash(): void
    {
        $out = Stage::step('cleanup', 7, 0, Theme::plain());
        $this->assertSame('▸ 7 cleanup', $out);
    }

    public function testCustomGlyph(): void
    {
        $out = Stage::step('go', 1, 1, Theme::plain(), Stage::GLYPH_HASH);
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
        $out = Stage::step('x', 1, 2, Theme::ansi());
        // ANSI accent style emits SGR; raw string contains ESC sequence.
        $this->assertStringContainsString("\x1b[", $out);
    }

    /** GLYPH_BULLET is currently the only un-exercised constant — close that gap. */
    public function testBulletGlyph(): void
    {
        $out = Stage::step('init', 1, 0, Theme::plain(), Stage::GLYPH_BULLET);
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

        // The frame must be one of the library's own cycle (single source —
        // a local copy here would silently survive a frames-table rewrite).
        $frame = mb_substr($out, -1);
        $this->assertContains($frame, Stage::SPINNER_FRAMES, 'trailing glyph must be a spinner frame');
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
            Stage::step($evil, 1, 2, Theme::plain()),
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
        $this->assertSame('▸ 2/5 building', Stage::step('building', 2, 5, Theme::plain()));
    }

    /** Width clamp cuts only the message; the ▸ N/M prefix is never touched. */
    public function testStepClipsLongMessage(): void
    {
        // prefix '▸ 1/2 ' = 6 cells; budget 12-6 = 6 → 5 z's + ellipsis.
        $out = Stage::step(str_repeat('z', 50), 1, 2, Theme::plain(), width: 12);
        $this->assertSame('▸ 1/2 zzzzz…', $out);
        $this->assertSame(12, Width::string($out));
    }

    /** A message that fits under the budget renders whole. */
    public function testStepFitsUnderWidth(): void
    {
        $this->assertSame('▸ 1/2 go', Stage::step('go', 1, 2, Theme::plain(), width: 12));
    }

    public function testSubStepClipsToWidth(): void
    {
        // prefix '  ├─ ' = 5 cells; budget 8-5 = 3 → 2 q's + ellipsis.
        $out = Stage::subStep(str_repeat('q', 30), Theme::plain(), isLast: false, indent: 2, width: 8);
        $this->assertSame('  ├─ qq…', $out);
    }

    /** The determinate bar reserves its suffix cells so the line stays in budget. */
    public function testProgressBarReservesSuffixCells(): void
    {
        // prefix 5 + bar(10) + pct(5) = 20 non-message cells; width 30 → 9-cell
        // message budget, of which the house ellipsis spends the last one.
        $out = Stage::subStepWithProgress(str_repeat('m', 30), 4, 10, Theme::plain(), isLast: false, indent: 2, width: 30);
        $this->assertSame('  ├─ mmmmmmmm… ████░░░░░░  40%', $out);
        $this->assertSame(30, Width::string($out));
    }

    /** Null width renders the whole message and bar (no clamp). */
    public function testProgressBarNullWidthNoClamp(): void
    {
        $long = str_repeat('m', 40);
        $out  = Stage::subStepWithProgress($long, 4, 10, Theme::plain(), isLast: false, indent: 2, width: null);
        $this->assertStringContainsString($long, $out);
    }

    /**
     * M9: an explicit $frame makes the indeterminate spinner byte-deterministic
     * (goldens/tests/recorded sessions); the index cycles modulo the frame count,
     * negatives backwards, and is ignored by the determinate bar.
     */
    public function testSpinnerFrameIsDeterministicWhenPinned(): void
    {
        $this->assertSame('  ├─ syncing ⠋', Stage::subStepWithProgress('syncing', 0, 0, Theme::plain(), frame: 0));
        $this->assertSame('  ├─ syncing ⠙', Stage::subStepWithProgress('syncing', 0, 0, Theme::plain(), frame: 1));
        // index 10 wraps to 0; -1 wraps to the last frame (⠏).
        $this->assertSame('  ├─ syncing ⠋', Stage::subStepWithProgress('syncing', 0, 0, Theme::plain(), frame: 10));
        $this->assertSame('  ├─ syncing ⠏', Stage::subStepWithProgress('syncing', 0, 0, Theme::plain(), frame: -1));
    }

    /** A pinned frame is stable across repeated calls (wall-clock bypassed). */
    public function testPinnedFrameStableAcrossCalls(): void
    {
        $a = Stage::subStepWithProgress('x', 0, 0, Theme::plain(), frame: 3);
        $b = Stage::subStepWithProgress('x', 0, 0, Theme::plain(), frame: 3);
        $this->assertSame($a, $b);
    }

    /** A width below 1 is an authoring error: throws rather than empty line. */
    public function testWidthBelowOneThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Stage::step('x', 1, 1, Theme::plain(), width: 0);
    }
}

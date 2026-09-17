<?php

declare(strict_types=1);

namespace SugarCraft\Bits\Tests\Progress;

use SugarCraft\Bits\Progress\Progress;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;
use PHPUnit\Framework\TestCase;


final class ProgressTest extends TestCase
{
    public function testZeroPercent(): void
    {
        $p = Progress::new()->withWidth(10)->withShowPercent(false)->withRunes('#', '.');
        $this->assertSame(str_repeat('.', 10), $p->withPercent(0.0)->view());
    }

    public function testFullPercent(): void
    {
        $p = Progress::new()->withWidth(10)->withShowPercent(false)->withRunes('#', '.');
        $this->assertSame(str_repeat('#', 10), $p->withPercent(1.0)->view());
    }

    public function testHalfPercent(): void
    {
        $p = Progress::new()->withWidth(10)->withShowPercent(false)->withRunes('#', '.');
        $this->assertSame('#####.....', $p->withPercent(0.5)->view());
    }

    public function testPercentClampedToZeroOne(): void
    {
        $p = Progress::new()->withWidth(10)->withShowPercent(false)->withRunes('#', '.');
        $this->assertSame(str_repeat('#', 10), $p->withPercent(2.0)->view());
        $this->assertSame(str_repeat('.', 10), $p->withPercent(-0.5)->view());
    }

    public function testWithPercentSuffix(): void
    {
        $p = Progress::new()->withWidth(10)->withRunes('#', '.')->withPercent(0.5);
        // 10 - 5 (" 100%") = 5 cells for bar; round(0.5*5) = 3 filled, 2 empty.
        $this->assertSame('###..  50%', $p->view());
    }

    public function testHundredPercentSuffix(): void
    {
        $p = Progress::new()->withWidth(10)->withRunes('#', '.')->withPercent(1.0);
        $this->assertSame(str_repeat('#', 5) . ' 100%', $p->view());
    }

    public function testFillColorWraps(): void
    {
        $p = Progress::new()
            ->withWidth(5)
            ->withShowPercent(false)
            ->withRunes('#', '.')
            ->withFillColor(Color::hex('#ff0000'))
            ->withColorProfile(ColorProfile::TrueColor)
            ->withPercent(1.0);
        $this->assertSame("\x1b[38;2;255;0;0m" . str_repeat('#', 5) . "\x1b[0m", $p->view());
    }

    public function testNegativeWidthRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Progress(width: -1);
    }

    public function testConstructorClampsPercentAboveOne(): void
    {
        $p = new Progress(percent: 1.5, width: 10, showPercent: false);
        $this->assertSame(1.0, $p->percent);
        // Should render full bar without crashing on negative str_repeat.
        $this->assertSame(str_repeat('█', 10), $p->view());
    }

    public function testConstructorClampsPercentBelowZero(): void
    {
        $p = new Progress(percent: -0.5, width: 10, showPercent: false);
        $this->assertSame(0.0, $p->percent);
        $this->assertSame(str_repeat('░', 10), $p->view());
    }

    public function testWidthSmallerThanPercentSuffixDropsSuffix(): void
    {
        // width=3 can't fit " 100%" — view() must respect the width budget.
        $p = Progress::new()->withRunes('#', '.')->withWidth(3)->withPercent(0.5);
        $this->assertSame(3, Width::string($p->view()));
    }

    public function testViewWidthMatchesConfiguredWidth(): void
    {
        $p = Progress::new()->withWidth(20)->withPercent(0.5);
        $this->assertSame(20, $p->viewWidth());
    }

    public function testWithColorsThreeStops(): void
    {
        $red    = Color::hex('#ff0000');
        $green  = Color::hex('#00ff00');
        $blue   = Color::hex('#0000ff');
        $p = Progress::new()
            ->withWidth(8)
            ->withShowPercent(false)
            ->withColors($red, $green, $blue)
            ->withPercent(1.0);
        $this->assertSame([$red, $green, $blue], $p->gradientStops);
        $rendered = $p->view();
        $this->assertStringContainsString("\x1b[38;2;255;0;0m", $rendered);
        $this->assertStringContainsString("\x1b[38;2;0;0;255m", $rendered);
    }

    public function testWithColorsSingleColorActsAsSolidFill(): void
    {
        $red = Color::hex('#ff0000');
        $p = Progress::new()->withColors($red)->withPercent(0.5);
        $this->assertSame($red, $p->fillColor);
        $this->assertSame([], $p->gradientStops);
    }

    public function testWithColorsEmptyClearsGradient(): void
    {
        $p = Progress::new()
            ->withGradient(Color::hex('#000'), Color::hex('#fff'))
            ->withColors();
        $this->assertSame([], $p->gradientStops);
        $this->assertNull($p->gradientStart);
        $this->assertNull($p->gradientEnd);
    }

    public function testWithColorFuncOverridesEverything(): void
    {
        $p = Progress::new()
            ->withWidth(4)
            ->withShowPercent(false)
            ->withColorFunc(static function (int $_i, int $_n, float $pct): Color {
                return Color::hex('#000000');
            })
            ->withPercent(1.0);
        $rendered = $p->view();
        $this->assertStringContainsString("\x1b[38;2;0;0;0m", $rendered);
    }

    public function testWithColorFuncNullClears(): void
    {
        $p = Progress::new()
            ->withColorFunc(static fn (int $i, int $n, float $pct) => Color::hex('#fff'))
            ->withColorFunc(null)
            ->withWidth(4)
            ->withShowPercent(false)
            ->withPercent(1.0);
        $this->assertStringNotContainsString("\x1b[38", $p->view());
    }

    public function testShowValueOnly(): void
    {
        $p = Progress::new()
            ->withWidth(10)
            ->withShowPercent(false)
            ->withShowValue(true)
            ->withRunes('#', '.')
            ->withPercent(0.5);
        // 50% of 10 = 5 cells filled, so current=5, total=10 → "5/10"
        $this->assertSame('#####..... 5/10', $p->view());
    }

    public function testShowValueWithPercent(): void
    {
        $p = Progress::new()
            ->withWidth(10)
            ->withShowPercent(true)
            ->withShowValue(true)
            ->withRunes('#', '.')
            ->withPercent(0.5);
        // Full bar width (10 cells), then suffix with percent and value
        // bar: 5 filled + 5 empty = 10, suffix: " 50% (5/10)"
        $this->assertSame('#####.....  50% (5/10)', $p->view());
    }

    public function testShowValueWithCustomFormat(): void
    {
        $p = Progress::new()
            ->withWidth(10)
            ->withShowPercent(false)
            ->withShowValue(true, '%d of %d items')
            ->withRunes('#', '.')
            ->withPercent(0.5);
        // Full bar width (10 cells), filled=5, empty=5, then suffix
        $this->assertSame('#####..... 5 of 10 items', $p->view());
    }

    public function testShowValueZeroPercent(): void
    {
        $p = Progress::new()
            ->withWidth(10)
            ->withShowPercent(false)
            ->withShowValue(true)
            ->withRunes('#', '.')
            ->withPercent(0.0);
        $this->assertSame('.......... 0/10', $p->view());
    }

    public function testShowValueFullPercent(): void
    {
        $p = Progress::new()
            ->withWidth(10)
            ->withShowPercent(false)
            ->withShowValue(true)
            ->withRunes('#', '.')
            ->withPercent(1.0);
        // 100% of 10 = 10, current=10, total=10 → "10/10"
        $this->assertSame('########## 10/10', $p->view());
    }

    public function testShowValueDefaultFormatIsPercent(): void
    {
        $p = Progress::new()
            ->withWidth(10)
            ->withShowPercent(false)
            ->withShowValue(true)
            ->withRunes('#', '.')
            ->withPercent(0.42);
        // 42% of 10 = 4.2 → rounds to 4
        $this->assertSame('####...... 4/10', $p->view());
    }

    public function testIncrPercent(): void
    {
        $p = Progress::new()->withWidth(10)->withShowPercent(false)->withRunes('#', '.')->withPercent(0.3);
        $incr = $p->incrPercent(0.2);
        $this->assertSame(0.5, $incr->percent);
    }

    public function testDecrPercent(): void
    {
        $p = Progress::new()->withWidth(10)->withShowPercent(false)->withRunes('#', '.')->withPercent(0.5);
        $decr = $p->decrPercent(0.2);
        $this->assertSame(0.3, $decr->percent);
    }

    public function testWithRunes(): void
    {
        $p = Progress::new()->withRunes('X', '-');
        $this->assertSame('X', $p->fullChar);
        $this->assertSame('-', $p->emptyChar);
    }

    public function testWithEmptyColor(): void
    {
        $c = \SugarCraft\Core\Util\Color::hex('#888888');
        $p = Progress::new()->withEmptyColor($c);
        $this->assertSame($c, $p->emptyColor);
    }

    public function testWithRenderModeLine(): void
    {
        $p = Progress::new()
            ->withWidth(5)
            ->withShowPercent(false)
            ->withPercent(0.5)
            ->withRenderMode(\SugarCraft\Bits\Progress\ProgressRenderMode::Line);
        $view = $p->view();
        // Line mode uses ━ (filled) and ─ (empty)
        $this->assertStringContainsString("\xe2\x94\x81", $view); // ━
        $this->assertStringContainsString("\xe2\x94\x80", $view); // ─
    }

    public function testWithRenderModeSlim(): void
    {
        $p = Progress::new()
            ->withWidth(5)
            ->withShowPercent(false)
            ->withPercent(0.5)
            ->withRenderMode(\SugarCraft\Bits\Progress\ProgressRenderMode::Slim);
        $view = $p->view();
        // Slim mode uses ▌ (filled) and ▒ (empty)
        $this->assertStringContainsString("\xe2\x96\x8c", $view); // ▌
        $this->assertStringContainsString("\xe2\x96\x92", $view); // ▒
    }

    public function testViewAsRendersAtGivenPercent(): void
    {
        $p = Progress::new()->withWidth(10)->withShowPercent(false)->withRunes('#', '.');
        $view = $p->viewAs(0.5);
        $this->assertSame('#####.....', $view);
    }

    public function testToStringCallsView(): void
    {
        $p = Progress::new()->withWidth(10)->withShowPercent(false)->withRunes('#', '.')->withPercent(0.5);
        $this->assertSame($p->view(), (string) $p);
    }

    /**
     * E743 capture-derived pins: exact Line-mode bytes. Line mode fills the
     * raw configured width (no suffix reservation) with ━ (U+2501) / ─
     * (U+2500), flat colours wrapping each run.
     */
    public function testLineModeExactBytesAcrossFitEdges(): void
    {
        $p = Progress::new()
            ->withRenderMode(\SugarCraft\Bits\Progress\ProgressRenderMode::Line)
            ->withWidth(7)
            ->withPercent(0.5);
        // round(0.5 * 7) = 4 filled, 3 empty — Line never yields cells to a suffix.
        $this->assertSame("\xe2\x94\x81\xe2\x94\x81\xe2\x94\x81\xe2\x94\x81\xe2\x94\x80\xe2\x94\x80\xe2\x94\x80", $p->view());

        $c = $p
            ->withFillColor(Color::hex('#ff0000'))
            ->withEmptyColor(Color::hex('#0000ff'));
        $this->assertSame(
            "\x1b[38;2;255;0;0m\xe2\x94\x81\xe2\x94\x81\xe2\x94\x81\xe2\x94\x81\x1b[0m"
            . "\x1b[38;2;0;0;255m\xe2\x94\x80\xe2\x94\x80\xe2\x94\x80\x1b[0m",
            $c->view()
        );
    }

    /**
     * E743: Line mode never renders percent text, so computeBarLayout's
     * withPercentSuffix=false gate must keep a caller-supplied malformed
     * percentFormat from ever reaching sprintf() on this path.
     */
    public function testLineModeIgnoresMalformedPercentFormat(): void
    {
        $p = Progress::new()
            ->withRenderMode(\SugarCraft\Bits\Progress\ProgressRenderMode::Line)
            ->withWidth(7)
            ->withPercent(0.5)
            ->withPercentFormat('%d %d');
        // Same bytes as the default-format Line render — the format is never applied.
        $this->assertSame("\xe2\x94\x81\xe2\x94\x81\xe2\x94\x81\xe2\x94\x81\xe2\x94\x80\xe2\x94\x80\xe2\x94\x80", $p->view());
    }

    /**
     * E743 capture-derived pins: exact Slim-mode bytes, including the
     * suffix-fit law — bar yields (measured pctText width + 1) cells to the
     * percent suffix only while the suffix genuinely fits.
     */
    public function testSlimModeExactBytesAcrossFitEdges(): void
    {
        $slim = \SugarCraft\Bits\Progress\ProgressRenderMode::Slim;
        // width 10, pct 0.5: suffix " 50%" is 4 cells + 1 space → bar keeps 5
        // cells; round(0.5 * 5) = 3 filled ▌ + 2 empty ▒, then ' ' . pctText.
        $p = Progress::new()->withRenderMode($slim)->withWidth(10)->withPercent(0.5);
        $this->assertSame("\xe2\x96\x8c\xe2\x96\x8c\xe2\x96\x8c\xe2\x96\x92\xe2\x96\x92  50%", $p->view());

        // width 4 cannot fit the 5-cell suffix reserve → suffix dropped, bar
        // spans the raw width: round(0.5 * 4) = 2 filled + 2 empty.
        $this->assertSame(
            "\xe2\x96\x8c\xe2\x96\x8c\xe2\x96\x92\xe2\x96\x92",
            $p->withWidth(4)->view()
        );

        // showPercent off: no suffix math at all, raw-width split.
        $this->assertSame(
            "\xe2\x96\x8c\xe2\x96\x8c\xe2\x96\x8c\xe2\x96\x8c\xe2\x96\x92\xe2\x96\x92\xe2\x96\x92",
            $p->withWidth(7)->withShowPercent(false)->view()
        );
    }

    /**
     * E743 capture-derived pins: Block-mode value-suffix polarity at narrow
     * widths. With percent AND value shown the bar never yields cells, and
     * when the percent no longer fits the whole suffix (value included) is
     * dropped; value-only mode ignores the percent fit and always appends.
     */
    public function testBlockValueSuffixPolarityAtNarrowWidths(): void
    {
        $p = Progress::new()->withWidth(4)->withShowValue(true)->withPercent(0.5);
        // percent+value, width 4: suffix " 50%" needs 5 cells → percentFits
        // false → bare bar, value text suppressed too.
        $this->assertSame('██░░', $p->view());

        // value-only at the same width: no percent suffix to fit, bar holds
        // the raw width and the value text always appends.
        $this->assertSame('██░░ 2/4', $p->withShowPercent(false)->view());
    }

    /**
     * E743 structural pin: the Line/Slim/Block branches must share ONE
     * geometry compute site. Rolling a branch back to inline math (or
     * neutering the helper into dead code) drifts these counts red.
     */
    public function testGeometryMathLivesAtASingleComputeSite(): void
    {
        $src = (string) file_get_contents(
            (new \ReflectionClass(Progress::class))->getFileName()
        );
        $this->assertSame(1, substr_count($src, 'function computeBarLayout('));
        // 3 call sites (Line, Slim, Block) + the definition header.
        $this->assertSame(4, substr_count($src, 'computeBarLayout('));
        // Bar-width rounding exists only inside the helper; the Block branch
        // keeps its own $current rounding for the value text, plus the
        // helper's percent×100 rounding: three round($this->percent) in all.
        $this->assertSame(3, substr_count($src, '(int) round($this->percent'));
        $this->assertSame(1, substr_count($src, 'Width::string($pctText) + 1'));
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Plot\Chart;

use SugarCraft\Dash\Plot\Chart\GaugeCircle;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class GaugeCircleTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testGaugeCircleImplementsSizer(): void
    {
        $gauge = GaugeCircle::new(0.5);
        $this->assertInstanceOf(Sizer::class, $gauge);
    }

    public function testGaugeCircleImplementsItem(): void
    {
        $gauge = GaugeCircle::new(0.5);
        $this->assertInstanceOf(Item::class, $gauge);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $gauge = GaugeCircle::new(0.5);
        $rendered = $gauge->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsNewlines(): void
    {
        $gauge = GaugeCircle::new(0.5);
        $rendered = $gauge->render();

        // Gauge is multi-line
        $this->assertStringContainsString("\n", $rendered);
    }

    public function testRenderContainsCircles(): void
    {
        $gauge = GaugeCircle::new(0.5);
        $rendered = $gauge->render();

        // Should contain circle characters
        $this->assertMatchesRegularExpression('/[●○◆]/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Ratio handling
    // ═══════════════════════════════════════════════════════════════

    public function testZeroRatio(): void
    {
        $gauge = GaugeCircle::new(0.0);
        $rendered = $gauge->render();

        // Should render without errors
        $this->assertNotSame('', $rendered);
    }

    public function testFullRatio(): void
    {
        $gauge = GaugeCircle::new(1.0);
        $rendered = $gauge->render();

        // Should render without errors
        $this->assertNotSame('', $rendered);
    }

    public function testHalfRatio(): void
    {
        $gauge = GaugeCircle::new(0.5);
        $rendered = $gauge->render();

        // Should render with both filled and empty
        $this->assertStringContainsString('●', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Ratio clamping
    // ═══════════════════════════════════════════════════════════════

    public function testNegativeRatioClampedToZero(): void
    {
        $gauge = new GaugeCircle(-0.5, 4, true, true, true, null, null, null);
        $rendered = $gauge->render();

        // Should render without errors
        $this->assertNotSame('', $rendered);
    }

    public function testOverOneRatioClampedToOne(): void
    {
        $gauge = new GaugeCircle(1.5, 4, true, true, true, null, null, null);
        $rendered = $gauge->render();

        // Should render without errors
        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Label display
    // ═══════════════════════════════════════════════════════════════

    public function testShowLabelByDefault(): void
    {
        $gauge = GaugeCircle::new(0.75);
        $rendered = $gauge->render();

        // Should contain "75%"
        $this->assertStringContainsString('75%', $rendered);
    }

    public function testHideLabel(): void
    {
        $gauge = GaugeCircle::new(0.75)->withShowLabel(false);
        $rendered = $gauge->render();

        // Should NOT contain percentage
        $this->assertStringNotContainsString('%', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Needle display
    // ═══════════════════════════════════════════════════════════════

    public function testShowNeedleByDefault(): void
    {
        $gauge = GaugeCircle::new(0.5);
        $rendered = $gauge->render();

        // Should contain needle character
        $this->assertStringContainsString('❮', $rendered);
    }

    public function testHideNeedle(): void
    {
        $gauge = GaugeCircle::new(0.5)->withShowNeedle(false);
        $rendered = $gauge->render();

        // Should still render, just without needle
        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Tick marks
    // ═══════════════════════════════════════════════════════════════

    public function testShowTicksByDefault(): void
    {
        $gauge = GaugeCircle::new(0.5);
        $rendered = $gauge->render();

        // Should contain tick characters
        $this->assertMatchesRegularExpression('/[┬┴│]/', $rendered);
    }

    public function testHideTicks(): void
    {
        $gauge = GaugeCircle::new(0.5)->withShowTicks(false);
        $rendered = $gauge->render();

        // Should still render without tick marks
        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Radius
    // ═══════════════════════════════════════════════════════════════

    public function testDifferentRadii(): void
    {
        $gaugeSmall = GaugeCircle::new(0.5)->withRadius(3);
        $gaugeLarge = GaugeCircle::new(0.5)->withRadius(8);

        $renderedSmall = $gaugeSmall->render();
        $renderedLarge = $gaugeLarge->render();

        // Larger radius should produce more output
        $this->assertGreaterThan(
            strlen($renderedSmall),
            strlen($renderedLarge)
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testArcColorAddsAnsiCodes(): void
    {
        $gauge = GaugeCircle::new(0.5)->withArcColor(Color::ansi(9));
        $rendered = $gauge->render();

        // Should contain ANSI color codes
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testNeedleColorAddsAnsiCodes(): void
    {
        $gauge = GaugeCircle::new(0.5)->withNeedleColor(Color::ansi(12));
        $rendered = $gauge->render();

        // Should contain ANSI color codes
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testLabelColorAddsAnsiCodes(): void
    {
        $gauge = GaugeCircle::new(0.5)->withLabelColor(Color::ansi(14));
        $rendered = $gauge->render();

        // Should contain ANSI color codes (for label)
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSize(): void
    {
        $gauge = GaugeCircle::new(0.5);
        [$w, $h] = $gauge->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThan(0, $h);
    }

    public function testGetInnerSizeWithLabel(): void
    {
        $gaugeWithLabel = GaugeCircle::new(0.5)->withShowLabel(true);
        $gaugeWithoutLabel = GaugeCircle::new(0.5)->withShowLabel(false);

        [$wWith, $hWith] = $gaugeWithLabel->getInnerSize();
        [$wWithout, $hWithout] = $gaugeWithoutLabel->getInnerSize();

        // With label should be taller
        $this->assertGreaterThan($hWithout, $hWith);
    }

    public function testGetInnerSizeWithDifferentRadii(): void
    {
        $gaugeSmall = GaugeCircle::new(0.5)->withRadius(4);
        $gaugeLarge = GaugeCircle::new(0.5)->withRadius(8);

        [$wSmall, $hSmall] = $gaugeSmall->getInnerSize();
        [$wLarge, $hLarge] = $gaugeLarge->getInnerSize();

        // Larger radius should have larger dimensions
        $this->assertGreaterThan($wSmall, $wLarge);
        $this->assertGreaterThan($hSmall, $hLarge);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithRatioReturnsNewInstance(): void
    {
        $original = GaugeCircle::new(0.25);
        $updated = $original->withRatio(0.75);

        $this->assertNotSame($original, $updated);
    }

    public function testWithRadiusReturnsNewInstance(): void
    {
        $original = GaugeCircle::new(0.5);
        $updated = $original->withRadius(8);

        $this->assertNotSame($original, $updated);
    }

    public function testWithShowNeedleReturnsNewInstance(): void
    {
        $original = GaugeCircle::new(0.5);
        $updated = $original->withShowNeedle(false);

        $this->assertNotSame($original, $updated);
    }

    public function testWithShowTicksReturnsNewInstance(): void
    {
        $original = GaugeCircle::new(0.5);
        $updated = $original->withShowTicks(false);

        $this->assertNotSame($original, $updated);
    }

    public function testWithShowLabelReturnsNewInstance(): void
    {
        $original = GaugeCircle::new(0.5);
        $updated = $original->withShowLabel(false);

        $this->assertNotSame($original, $updated);
    }

    public function testWithArcColorReturnsNewInstance(): void
    {
        $original = GaugeCircle::new(0.5);
        $updated = $original->withArcColor(Color::ansi(1));

        $this->assertNotSame($original, $updated);
    }

    public function testWithNeedleColorReturnsNewInstance(): void
    {
        $original = GaugeCircle::new(0.5);
        $updated = $original->withNeedleColor(Color::ansi(1));

        $this->assertNotSame($original, $updated);
    }

    public function testWithLabelColorReturnsNewInstance(): void
    {
        $original = GaugeCircle::new(0.5);
        $updated = $original->withLabelColor(Color::ansi(1));

        $this->assertNotSame($original, $updated);
    }

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = GaugeCircle::new(0.5);
        $resized = $original->setSize(20, 20);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testMinimumRadius(): void
    {
        $gauge = GaugeCircle::new(0.5)->withRadius(3);
        $rendered = $gauge->render();

        // Should still render
        $this->assertNotSame('', $rendered);
    }

    public function testFullCircle(): void
    {
        $gauge = GaugeCircle::new(1.0)->withPercentage(true);
        $rendered = $gauge->render();

        $this->assertStringContainsString('100%', $rendered);
    }

    public function testEmptyCircle(): void
    {
        $gauge = GaugeCircle::new(0.0)->withPercentage(true);
        $rendered = $gauge->render();

        $this->assertStringContainsString('0%', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Uncolored (null arcColor) ring honors ratio — C8a ring-bug fix
    // ═══════════════════════════════════════════════════════════════

    public function testUncoloredRingHonorsRatio(): void
    {
        // Bare ctor, arcColor null, needle/ticks/label off — isolate the arc.
        $mid = (new GaugeCircle(0.3, 6, false, false, false))->render();
        $this->assertStringContainsString('●', $mid, 'filled sweep must remain');
        $this->assertStringContainsString('○', $mid, 'uncolored remainder was unreachable (full-ring bug)');

        $zero = (new GaugeCircle(0.0, 6, false, false, false))->render();
        $this->assertStringContainsString('○', $zero, 'ratio 0 → ring is remainder ○');

        $full = (new GaugeCircle(1.0, 6, false, false, false))->render();
        $this->assertStringContainsString('●', $full, 'ratio 1 → filled');
        $this->assertStringNotContainsString('○', $full, 'ratio 1 → no remainder');
    }

    public function testUncoloredShapeMatchesColoredShape(): void
    {
        // Same $isFilled predicate governs ●/○ in both modes (color only gates ANSI).
        $uncolored = (new GaugeCircle(0.3, 6, false, false, false))->render();
        $colored = (new GaugeCircle(0.3, 6, false, false, false, Color::ansi(9)))->render();
        $strip = static fn(string $s): string => (string) preg_replace('/\x1b\[[0-9;]*m/', '', $s);

        $this->assertSame($strip($uncolored), $strip($colored), 'grid shape must be color-agnostic');
        $this->assertStringContainsString('○', $colored, 'colored path behavior unchanged');

        $low = substr_count((new GaugeCircle(0.25, 6, false, false, false))->render(), '●');
        $high = substr_count((new GaugeCircle(0.75, 6, false, false, false))->render(), '●');
        $this->assertGreaterThan($low, $high, '● count grows with ratio');
    }

    // ═══════════════════════════════════════════════════════════════
    // setSize geometry activation (v5 D2)
    // ═══════════════════════════════════════════════════════════════

    public function testWithAspectOneLegacyEscapeIsByteIdenticalToD2(): void
    {
        // R2 moved the default-freeze role: the DEFAULT now renders at
        // aspect 2.0 (visually round). The byte-identity gate lives on
        // withAspect(1.0) instead — geometry at 1.0 equals the D2-shipped
        // bytes exactly (division by 1.0 is bit-exact IEEE). Shas re-derived
        // live 2026-09-03 against the HEAD (D2) class; the last two are the
        // golden bodies themselves (example chain == replay harness output).
        $escape = GaugeCircle::new(0.8)->withAspect(1.0);

        $this->assertSame('2186ff670a87f0500b70148b0356b504108f1d7a', sha1($escape->render()));
        $this->assertSame(1063, strlen($escape->render()));
        $this->assertSame([13, 14], $escape->getInnerSize());

        $sized = GaugeCircle::new(0.8)->setSize(40, 10)->withAspect(1.0);
        $this->assertSame('347aeae53763671ddcfdb2b55a285058f03e83db', sha1($sized->render()));
        $this->assertSame(731, strlen($sized->render()));

        $uncolored = (new GaugeCircle(0.3, 6, false, false, false))->withAspect(1.0);
        $this->assertSame('c369548d338735f2d29c08781706f453dfb9d9be', sha1($uncolored->render()));

        $example80 = GaugeCircle::new(80)->setSize(80, 24)->withAspect(1.0);
        $this->assertSame('4b780628c8e5ecfa66c89f0fc8b9c43077d981c3', sha1($example80->render()));
        $this->assertSame(2158, strlen($example80->render()));

        $example120 = GaugeCircle::new(80)->setSize(120, 40)->withAspect(1.0);
        $this->assertSame('dfa434e2099f830b7a688521ff190544d7b54e93', sha1($example120->render()));
        $this->assertSame(4318, strlen($example120->render()));
    }

    public function testAspectDefaultsToTwoRoundDial(): void
    {
        // DECLARED-OUTPUT-CHANGE (R2): gauges ship visually round — null
        // aspect resolves to DEFAULT_ASPECT 2.0, matching Donut, and the
        // default render MOVES away from the legacy raw-cell bytes.
        $gauge = GaugeCircle::new(0.8);
        $aspect = (new \ReflectionMethod($gauge, 'aspect'))->invoke($gauge);

        $this->assertSame(2.0, $aspect);
        $this->assertSame(2.0, (new \ReflectionMethod($gauge, 'aspect'))->invoke($gauge->withAspect()));
        $this->assertNotSame(
            $gauge->withAspect(1.0)->render(),
            $gauge->render(),
            'default aspect 2.0 must re-render the dial (R2 declared change)'
        );
        $this->assertSame($gauge->render(), $gauge->withAspect(2.0)->render(), 'explicit 2.0 == null default');
    }

    public function testWithAspectRejectsNonPositiveAndNonFinite(): void
    {
        // B5 guard parity with Donut::withAspect — silent NaN/zero would
        // poison every stamped offset, so bad ratios fail loud at the gate.
        foreach ([0.0, -2.0, NAN, INF, -INF] as $bad) {
            try {
                GaugeCircle::new(0.5)->withAspect($bad);
                $this->fail(sprintf('aspect ratio %s must be rejected', var_export($bad, true)));
            } catch (\InvalidArgumentException $e) {
                $this->assertMatchesRegularExpression('/^Invalid gauge aspect ratio ".*"; expected a finite positive float\.$/', $e->getMessage());
            }
        }

        // Tiny positive survives (no clamp — the guard only rejects illegal).
        $this->assertSame(0.01, (new \ReflectionProperty(GaugeCircle::class, 'aspect'))->getValue(GaugeCircle::new(0.5)->withAspect(0.01)));
    }

    public function testSmoothRimEmitsQuadrantRunesAndNoBinaryRim(): void
    {
        $render = GaugeCircle::new(0.5)->withSmoothRim()->render();
        $strip = (string) preg_replace('/\x1b\[[0-9;]*m/', '', $render);

        $this->assertStringNotContainsString('●', $strip, 'quadrant mode must not leak the binary filled rune');
        $this->assertStringNotContainsString('○', $strip, 'quadrant mode must not leak the binary remainder rune');
        $this->assertMatchesRegularExpression('/[▘▝▀▖▌▞▛▗▚▐▜▄▙▟█]/u', $strip, 'opt-in rim emits quadrant runes');
        // Every non-legacy grid rune is a declared quadrant mask.
        $legacy = [' ', '┬', '│', '┴', '❮', '◆', "\n"];
        foreach (mb_str_split($strip) as $rune) {
            if (in_array($rune, $legacy, true) || preg_match('/[0-9%]/', $rune)) {
                continue;
            }
            $this->assertContains($rune, array_values((new \ReflectionClass(GaugeCircle::class))->getConstant('QUADRANT_RUNES')), "rune {$rune} must belong to the declared quadrant set");
        }
        $this->assertStringContainsString('❮', $render, 'needle survives the rim rework');
        $this->assertStringContainsString('◆', $render, 'hub survives the rim rework');
        $this->assertMatchesRegularExpression('/[┬┴│]/u', $render, 'ticks survive the rim rework');
        $this->assertStringContainsString('50%', $render);
    }

    public function testSmoothRimDefaultsOffForByteIdentity(): void
    {
        // Donut precedent: the rim path is byte-identical while un-opted.
        $this->assertSame(
            GaugeCircle::new(0.5)->render(),
            GaugeCircle::new(0.5)->withSmoothRim(false)->render()
        );
        $this->assertFalse((new \ReflectionProperty(GaugeCircle::class, 'smoothRim'))->getValue(GaugeCircle::new(0.5)));
    }

    public function testAspectAndSmoothRimSurviveWitherChain(): void
    {
        // New fields must propagate through every wither AND the setSize
        // allocation must keep rendering through withAspect (D2 chain test
        // extended, not replaced).
        $base = GaugeCircle::new(0.4)->withAspect(1.5)->withSmoothRim()->setSize(40, 10);
        $chained = $base
            ->withRatio(0.4)
            ->withShowNeedle(true)
            ->withShowTicks(true)
            ->withShowLabel(true)
            ->withPercentage(true)
            ->withRadius(6)
            ->withArcColor(Color::hex('#874BFD'))
            ->withNeedleColor(Color::hex('#FF6B6B'))
            ->withLabelColor(Color::hex('#FFFFFF'));

        $this->assertNotSame($base, $chained);
        $this->assertSame($base->render(), $chained->render(), 'aspect/smoothRim/allocation must all survive the chain');
        $this->assertSame([9, 10], $chained->getInnerSize());
        $this->assertSame(1.5, (new \ReflectionProperty(GaugeCircle::class, 'aspect'))->getValue($chained));
    }

    public function testAllocationSurvivesFullWitherChain(): void
    {
        // All 9 withers must re-apply the allocation (GaugeWithDetail
        // allocation-preserving pattern) — StackedGrid:201-202 depends on
        // setSize(...)->render() geometry surviving fluent chains.
        $sized = GaugeCircle::new(0.8)->setSize(40, 10);
        $chained = $sized
            ->withRatio(0.8)
            ->withShowNeedle(true)
            ->withShowTicks(true)
            ->withShowLabel(true)
            ->withPercentage(true)
            ->withRadius(6)
            ->withArcColor(Color::hex('#874BFD'))
            ->withNeedleColor(Color::hex('#FF6B6B'))
            ->withLabelColor(Color::hex('#FFFFFF'));

        $this->assertSame($sized->render(), $chained->render(), 'wither chain must not drop the allocation');
        $this->assertNotSame(GaugeCircle::new(0.8)->render(), $chained->render(), 'sized render must differ from radius-6 default');
        $this->assertSame([9, 10], $chained->getInnerSize());
    }

    public function testCrossDimensionAllocationsRenderDifferently(): void
    {
        $small = GaugeCircle::new(0.5)->setSize(40, 10);
        $large = GaugeCircle::new(0.5)->setSize(60, 20);

        $smallLines = substr_count($small->render(), "\n") + 1;
        $largeLines = substr_count($large->render(), "\n") + 1;

        $this->assertNotSame($small->render(), $large->render(), 'cross-dim allocations must render differently');
        $this->assertSame([9, 10], $small->getInnerSize());
        $this->assertSame([19, 20], $large->getInnerSize());
        $this->assertSame(10, $smallLines, '40x10 → radius 4 → 9 grid rows + label');
        $this->assertSame(20, $largeLines, '60x20 → radius 9 → 19 grid rows + label');
    }

    public function testAllocationClampsToMinRadiusFloor(): void
    {
        // Allocation smaller than the clamp floor renders the floor dial
        // (radius 3, mirroring withRadius's max(3, …)), never a broken grid.
        $tiny = GaugeCircle::new(0.5)->setSize(4, 4);

        $this->assertSame([7, 8], $tiny->getInnerSize());
        $this->assertNotSame('', $tiny->render());
        $this->assertSame([7, 8], $tiny->withRatio(0.9)->getInnerSize(), 'floor survives withers');
    }
}
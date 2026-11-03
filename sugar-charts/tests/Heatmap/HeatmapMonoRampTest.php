<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests\Heatmap;

use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\Heatmap\Heatmap;
use SugarCraft\Charts\Support\UntrustedText;
use SugarCraft\Core\Util\Color;

/**
 * Pins for the review-fix batch on Heatmap: the monochrome density ramp +
 * CSV export added for colourless hosts and colour-blind viewers, the rune
 * injection guard, and the style-cache key shape.
 */
final class HeatmapMonoRampTest extends TestCase
{
    /** @param array<int, array<int, float>> $grid */
    private function heatmap(array $grid): Heatmap
    {
        return Heatmap::new($grid)->withSize(2, 2);
    }

    public function testMonoRampSubstitutesDensityGlyphsForColourBlocks(): void
    {
        $view = $this->heatmap([[0.0, 1.0], [0.5, 0.2]])
            ->withMin(0.0)
            ->withMax(1.0)
            ->withMonoRamp()
            ->view();
        $rows = explode("\n", $view);

        // Ramp [' ', '·', ':', '░', '▒', '▓', '█'] with fixed min/max:
        // t=0 → ' ', t=1 → '█', t=0.5 → '░', t=0.2 → index 1 '·'.
        $this->assertSame(" █", $rows[0]);
        $this->assertSame("░·", $rows[1]);
        $this->assertStringNotContainsString("\x1b", $view);
    }

    public function testMonoRampLegendCarriesNoColourSequences(): void
    {
        $view = $this->heatmap([[0.0, 1.0], [0.5, 0.2]])
            ->withMonoRamp()
            ->withLegend(true)
            ->view();

        $this->assertMatchesRegularExpression('/[·░▒▓█]/u', $view);
        $this->assertStringNotContainsString("\x1b[38", $view);
    }

    public function testToCsvExportsCommaSeparatedValues(): void
    {
        $csv = Heatmap::new([[0.0, 1.0], [0.5, 0.2]])->toCsv();
        $this->assertSame("0,1\n0.5,0.2", $csv);
    }

    public function testToCsvHonoursCustomSeparator(): void
    {
        $csv = Heatmap::new([[1.5, 2.0]])->toCsv(';');
        $this->assertSame("1.5;2", $csv);
    }

    public function testRuneIsGuardedAgainstTerminalInjection(): void
    {
        $poisoned = "\x1b[2J" . "\x1b]8;;http://evil\x07prompt\x0end";
        $h = Heatmap::new([[1.0]])->withRune($poisoned);

        $this->assertSame(UntrustedText::guard($poisoned), $h->rune);
        $this->assertStringNotContainsString("\x1b", $h->rune);
        $this->assertStringNotContainsString("\x07", $h->rune);
        $this->assertStringNotContainsString("\x0e", $h->rune);
        $this->assertStringNotContainsString("\x1b[2J", $h->view());
    }

    public function testWithRuneAliasSharesTheGuard(): void
    {
        $h = Heatmap::new([[1.0]])->rune("\nblock");
        $this->assertStringStartsWith(' ', $h->rune);
        $this->assertStringNotContainsString("\n", $h->rune);
    }

    public function testMonoRampFlagSurvivesResizeThroughCopy(): void
    {
        $h = Heatmap::new([[0.5]])->withMonoRamp();
        $this->assertTrue($h->getMonoRamp());
        // withSize() round-trips through copy(); the mode flag must carry.
        $this->assertTrue($h->withSize(4, 2)->getMonoRamp());
        $this->assertTrue($h->monoRamp()->getMonoRamp());
    }

    public function testStyleCacheKeyIncludesThePaletteIndex(): void
    {
        // Structural pin: sample() blends away palette indices today, so no
        // reachable byte-level discriminant exists through view(); the key
        // shape must nevertheless mirror LineChart's pointStyle memo, which
        // does receive indexed colors directly from datasets. A future
        // non-blending colour path would silently collide without it.
        $source = (string) file_get_contents(__DIR__ . '/../../src/Heatmap/Heatmap.php');
        $this->assertMatchesRegularExpression(
            '/\$key = \(\(\$color->ansiIndex \?\? -1\) << 24\) \| \(\$color->r << 16\)/',
            $source,
        );

        // Colour identity sanity: two Colors may share a triple yet differ
        // by index — the property the key guards.
        $plain = Color::rgb(255, 0, 0);
        $indexed = Color::ansi256(196);
        $this->assertSame([$indexed->r, $indexed->g, $indexed->b], [$plain->r, $plain->g, $plain->b]);
        $this->assertNull($plain->ansiIndex);
        $this->assertSame(196, $indexed->ansiIndex);
    }
}

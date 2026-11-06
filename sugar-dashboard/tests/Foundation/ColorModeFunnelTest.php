<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Foundation;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Dashboard\Foundation\ColorMode;
use SugarCraft\Dashboard\Foundation\Style;
use SugarCraft\Dashboard\Foundation\Theme;
use SugarCraft\Dashboard\Output\RenderBar;
use SugarCraft\Dashboard\Plot\Braille\BrailleCanvas;
use SugarCraft\Dashboard\Plot\Braille\DualSampleGraph;
use SugarCraft\Dashboard\Plot\ProcRow\ProcRow;
use SugarCraft\Dashboard\Plot\ProcRow\ProcRowComposer;

/**
 * Finding M-7 split-brain keystone, second half: ColorMode::use() must win
 * at EVERY emission site.
 *
 * Six renderers resolved their capability by calling ColorProfile::detect()
 * (or hardcoding TrueColor) on their own; each one was a second authority
 * that ignored a host's ColorMode::use() seed. These pins drive every former
 * bypass site through the process funnel: under a NoTty override no site may
 * emit colour SGR, and the bytes must not freeze when the override flips.
 * The census method keeps that invariant closed for future renderers.
 */
final class ColorModeFunnelTest extends TestCase
{
    /** Colour SGR introducers — an emission under an override must not carry these. */
    private const COLOUR_MARKS = ['38;', '48;'];

    protected function setUp(): void
    {
        ColorMode::resetForTesting();
    }

    protected function tearDown(): void
    {
        ColorMode::resetForTesting();
    }

    public function testRenderBarHonoursTheOverride(): void
    {
        ColorMode::use(ColorProfile::NoTty);
        $plain = RenderBar::render(0.5, 10, "\u{2588}", "\u{2591}", Color::hex('#FF0000'), Color::hex('#00FF00'));
        $segmented = RenderBar::renderSegmented(0.5, 10, Color::hex('#0000FF'));
        $this->assertNoColourBytes($plain);
        $this->assertNoColourBytes($segmented);

        ColorMode::use(ColorProfile::TrueColor);
        $coloured = RenderBar::render(0.5, 10, "\u{2588}", "\u{2591}", Color::hex('#FF0000'), Color::hex('#00FF00'));
        $colouredSegmented = RenderBar::renderSegmented(0.5, 10, Color::hex('#0000FF'));
        $this->assertStringContainsString('38;2;', $coloured);
        $this->assertStringContainsString('38;2;', $colouredSegmented);
        $this->assertNotSame($plain, $coloured, 'the bar must react to the process override');
    }

    public function testThemeFgBgHonourTheOverride(): void
    {
        $theme = Theme::dark();

        ColorMode::use(ColorProfile::NoTty);
        $this->assertSame('', $theme->fg());
        $this->assertSame('', $theme->bg());

        ColorMode::use(ColorProfile::TrueColor);
        $this->assertStringContainsString('38;2;', $theme->fg());
        $this->assertStringContainsString('48;2;', $theme->bg());
    }

    public function testDualSampleGraphHonoursTheOverride(): void
    {
        // A colour-bearing render needs a gradient: cells only carry an SGR
        // when the graph owns a ramp (see DualSampleGraph::render()).
        ColorMode::use(ColorProfile::NoTty);
        $plain = $this->gradientGraph()->render();
        $this->assertNoColourBytes($plain);

        ColorMode::use(ColorProfile::TrueColor);
        $coloured = $this->gradientGraph()->render();
        $this->assertStringContainsString('38;', $coloured, 'the graph must react to the process override');
        $this->assertNotSame($plain, $coloured, 'the two profiles must not answer identically');
    }

    public function testUnderlayHonoursTheOverride(): void
    {
        ColorMode::use(ColorProfile::NoTty);
        $this->assertNoColourBytes(DualSampleGraph::underlay(DualSampleGraph::FAMILY_BRAILLE, 3, Color::hex('#444444')));

        ColorMode::use(ColorProfile::TrueColor);
        $this->assertStringContainsString('38;', DualSampleGraph::underlay(DualSampleGraph::FAMILY_BRAILLE, 3, Color::hex('#444444')));
    }

    public function testBrailleCanvasHonoursTheOverride(): void
    {
        ColorMode::use(ColorProfile::NoTty);
        $plain = BrailleCanvas::new(2, 4)->setPoint(0, 0, Color::hex('#ff0000'))->render();
        $this->assertNoColourBytes($plain);

        ColorMode::use(ColorProfile::TrueColor);
        $coloured = BrailleCanvas::new(2, 4)->setPoint(0, 0, Color::hex('#ff0000'))->render();
        $this->assertStringContainsString('38;', $coloured);
        $this->assertNotSame($plain, $coloured);
    }

    public function testProcRowHonoursTheOverride(): void
    {
        $proc = ProcRow::new(42, 'php', 'php artisan queue:work --tries=3', 12, 'joe', 37.5, 4.2);

        ColorMode::use(ColorProfile::NoTty);
        $plain = ProcRowComposer::new(78)->row($proc, 0, 0, 20, null, false);
        $this->assertNoColourBytes($plain);
        // The composer's own rule: NoTty drops the bold decoration too.
        $this->assertStringNotContainsString("\x1b[1m", $plain);

        ColorMode::use(ColorProfile::TrueColor);
        $coloured = ProcRowComposer::new(78)->row($proc, 0, 0, 20, null, false);
        $this->assertStringContainsString('38;', $coloured);
        $this->assertNotSame($plain, $coloured);
    }

    public function testStyleToAnsiHonoursTheOverride(): void
    {
        $style = new Style(
            foreground: Color::hex('#FF0000'),
            background: Color::hex('#0000FF'),
            bold: true,
        );

        ColorMode::use(ColorProfile::NoTty);
        $plain = $style->toAnsi();
        $this->assertNoColourBytes($plain);
        $this->assertStringContainsString("\x1b[1m", $plain, 'decoration survives; only colour collapses');

        ColorMode::use(ColorProfile::TrueColor);
        $coloured = $style->toAnsi();
        $this->assertStringContainsString('38;2;', $coloured);
        $this->assertStringContainsString('48;2;', $coloured);
    }

    /**
     * Fail-closed census (finding M-7): no renderer under src/ may resolve
     * its own capability with ColorProfile::detect() again — the one legal
     * caller is the host-seeding guidance inside ColorMode itself.
     */
    public function testNoRendererBypassesTheFunnel(): void
    {
        $needle = 'ColorProfile::detect(';
        $bypasses = [];
        $srcRoot = dirname(__DIR__, 2) . '/src';

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcRoot));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            foreach (explode("\n", (string) file_get_contents($file->getPathname())) as $line) {
                $trimmed = ltrim($line);
                // Docblock and comment mentions are guidance, not a bypass.
                if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '/*')) {
                    continue;
                }
                if (str_contains($line, $needle)) {
                    $bypasses[] = $file->getPathname() . ': ' . trim($line);
                }
            }
        }

        $this->assertSame([], $bypasses, 'every emission site must read ColorMode::profile() (finding M-7)');
    }

    private function assertNoColourBytes(string $rendered, string $message = ''): void
    {
        foreach (self::COLOUR_MARKS as $mark) {
            $this->assertStringNotContainsString($mark, $rendered, $message);
        }
    }

    /**
     * Four samples over a 4-cell axis with a red ramp, so a colour-capable
     * profile emits per-cell 38;2; SGRs while a plain profile does not.
     */
    private function gradientGraph(): DualSampleGraph
    {
        return DualSampleGraph::new(4, 1, maxValue: 4)
            ->withGradient([Color::rgb(0, 0, 0), Color::rgb(100, 0, 0)])
            ->withData(1, 2, 3, 4);
    }
}

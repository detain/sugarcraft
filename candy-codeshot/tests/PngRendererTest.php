<?php

declare(strict_types=1);

namespace SugarCraft\CodeShot\Tests;

use SugarCraft\CodeShot\PngRenderer;
use SugarCraft\CodeShot\Theme;
use PHPUnit\Framework\TestCase;

final class PngRendererTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // cs-21 (TEST-04): ext-gd is suggest-by-design in this lib's
        // composer.json, but every render() here hard-requires it - on
        // gd-less hosts the whole class used to land as ~11 RuntimeException
        // ERRORS instead of skips. One class-level guard; the scattered
        // per-test guards are gone.
        if (!extension_loaded('gd')) {
            self::markTestSkipped('ext-gd not loaded');
        }
    }
    public function testRendersPng(): void
    {
        $png = PngRenderer::dark()->render("hello world\n");
        // PNG signature: \x89PNG\r\n\x1a\n
        $this->assertSame("\x89PNG\r\n\x1a\n", substr($png, 0, 8));
    }

    public function testRespectsPadding(): void
    {
        $small = PngRenderer::dark()->withPadding(10)->render("x\n");
        $large = PngRenderer::dark()->withPadding(50)->render("x\n");
        // Larger padding = larger image.
        $this->assertGreaterThan(strlen($small), strlen($large));
    }

    public function testRespectsWindow(): void
    {
        $withWindow = PngRenderer::dark()->withWindow(true)->render("x\n");
        $withoutWindow = PngRenderer::dark()->withWindow(false)->render("x\n");
        // Window adds height, so size differs.
        $this->assertGreaterThan(strlen($withoutWindow), strlen($withWindow));
    }

    public function testRespectsBackgroundColor(): void
    {
        // Dark theme: #0d1117
        $dark = PngRenderer::dark()->withWindow(false)->withShadow(false)->render("x\n");
        // Light theme: #f6f8fa
        $light = PngRenderer::light()->withWindow(false)->withShadow(false)->render("x\n");
        // Different background colours produce different bytes.
        $this->assertNotEquals($dark, $light);
    }

    public function testEmptyRendersMinimal(): void
    {
        $png = PngRenderer::dark()->withWindow(false)->render("");
        $this->assertSame("\x89PNG\r\n\x1a\n", substr($png, 0, 8));
    }

    public function testWithTheme(): void
    {
        $dracula = PngRenderer::dracula()->withWindow(false)->withShadow(false)->render("x\n");
        $tokyo   = PngRenderer::tokyoNight()->withWindow(false)->withShadow(false)->render("x\n");
        // Different themes produce different output.
        $this->assertNotEquals($dracula, $tokyo);
    }

    public function testAnsiColorsParsed(): void
    {
        // ANSI 31 = bright red @ #cd0000.
        $png = PngRenderer::dark()->withWindow(false)->withShadow(false)->render("\x1b[31mred\x1b[0m text\n");
        // Just verify it renders without error and is valid PNG.
        $this->assertSame("\x89PNG\r\n\x1a\n", substr($png, 0, 8));
    }

    public function testAnsiBackgroundColorsRenderInPng(): void
    {
        // The PNG backend honours ANSI 44 background bands: a banded render
        // cannot be byte-identical to the plain one. (The old pin only
        // checked the PNG signature and passed vacuously.)
        $banded = PngRenderer::dark()->withWindow(false)->withShadow(false)
            ->render("\x1b[44mblue background\x1b[0m\n");
        $plain = PngRenderer::dark()->withWindow(false)->withShadow(false)
            ->render("blue background\n");

        $this->assertSame("\x89PNG\r\n\x1a\n", substr($banded, 0, 8));
        $this->assertNotSame(md5($banded), md5($plain));
    }

    public function testThemePresetsProduceDifferentOutput(): void
    {
        $presets = [
            PngRenderer::dark(),
            PngRenderer::light(),
            PngRenderer::dracula(),
            PngRenderer::tokyoNight(),
            PngRenderer::nord(),
        ];

        $hashes = [];
        foreach ($presets as $renderer) {
            $png = $renderer->withWindow(false)->withShadow(false)->render("x\n");
            $hashes[] = md5($png);
        }

        // All 5 themes should produce distinct output.
        $this->assertCount(5, array_unique($hashes));
    }

    public function testShadowIncreasesSize(): void
    {
        $noShadow = PngRenderer::dark()->withShadow(false)->render("x\n");
        $withShadow = PngRenderer::dark()->withShadow(true)->render("x\n");
        // Shadow adds margin, so size is larger.
        $this->assertGreaterThan(strlen($noShadow), strlen($withShadow));
    }

    public function testShadowBandIsFilledInPlaceOnASingleCanvas(): void
    {
        // cs-10: the shadow used to be a SECOND full-canvas truecolor buffer
        // blitted over the frame by a whole-canvas imagecopy — peak GD memory
        // 2x canvas for one opaque rect. With blending off the copy is
        // "rect pixels inside, transparent outside" onto the already
        // transparent fill, so the in-place fill is byte-identical (md5-
        // proven against the pre-cure implementation at commit time). This
        // pins the CONTRACT the blit provided: the offset L-band carries the
        // shadow pixels and nothing beyond it leaks onto the canvas.
        // Geometry for this shape: frame 8x16 at (32,32), shadow rect
        // (38,38)-(45,53), canvas 72x80 (shadow margin 32, offset 6).
        $png = PngRenderer::dark()->withWindow(false)->withBorder(false)->withPadding(0)->render("x\n");
        $im = imagecreatefromstring($png);
        $this->assertNotFalse($im);

        try {
            foreach ([[43, 43], [43, 50], [41, 49]] as [$x, $y]) {
                $c = imagecolorat($im, $x, $y);
                $this->assertSame(0, $c & 0xFF, "shadow pixel ($x,$y) blue");
                $this->assertSame(0, ($c >> 8) & 0xFF, "shadow pixel ($x,$y) green");
                $this->assertSame(0, ($c >> 16) & 0xFF, "shadow pixel ($x,$y) red");
                $this->assertSame(60, ($c >> 24) & 0x7F, "shadow pixel ($x,$y) alpha");
            }
            foreach ([[5, 5], [70, 78], [60, 45]] as [$x, $y]) {
                $c = imagecolorat($im, $x, $y);
                $this->assertSame(127, ($c >> 24) & 0x7F, "pixel ($x,$y) outside the band must stay transparent");
            }
        } finally {
            imagedestroy($im);
        }
    }

    public function testLineNumbersIncreaseWidth(): void
    {
        $noLineNums = PngRenderer::dark()->withLineNumbers(false)->withWindow(false)->render("x\n");
        $withLineNums = PngRenderer::dark()->withLineNumbers(true)->withWindow(false)->render("x\n");
        // Line numbers add gutter, so size is larger.
        $this->assertGreaterThan(strlen($noLineNums), strlen($withLineNums));
    }

    public function testRenderTwiceInOneProcessProducesIdenticalOutput(): void
    {
        $first  = PngRenderer::dark()->withWindow(false)->withShadow(false)->render("hello\n");
        $second = PngRenderer::dark()->withWindow(false)->withShadow(false)->render("hello\n");
        $this->assertSame($first, $second);
    }

    public function testWindowStylesProduceDistinctOutput(): void
    {
        $styles = ['macos', 'windows-terminal', 'iterm', 'hyper'];
        $outputs = [];
        foreach ($styles as $style) {
            $outputs[$style] = PngRenderer::dark()
                ->withWindowStyle($style)
                ->withShadow(false)
                ->render("x\n");
        }

        $values = array_values($outputs);
        $valueCount = count($values);
        for ($i = 0; $i < $valueCount; $i++) {
            for ($j = $i + 1; $j < $valueCount; $j++) {
                $this->assertNotSame(
                    $values[$i],
                    $values[$j],
                    "Window style $styles[$i] and $styles[$j] produced identical output",
                );
            }
        }
    }

    public function testMalformedThemeColourIsRefusedNotPaintedBlack(): void
    {
        $theme = new Theme(
            background:   'oops',
            foreground:   '#c9d1d9',
            border:       '#30363d',
            shadow:       'rgba(0, 0, 0, 0.5)',
            lineNumber:   '#6e7681',
            windowRed:    '#ff5f56',
            windowYellow: '#ffbd2e',
            windowGreen:  '#27c93f',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("PNG colour must be a 6-digit #rrggbb hex string, got 'oops'");

        (new PngRenderer($theme))->withWindow(false)->withShadow(false)->render("x\n");
    }

    public function testOversizedPngIsRefusedNotAllocated(): void
    {
        // A 5000-column line at 900px padding asks for far more than the
        // 40-megapixel budget; the renderer must refuse, not allocate.
        $renderer = new PngRenderer(
            theme: Theme::dark(),
            padding: 900,
            window: false,
            shadow: false,
            border: false,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('too large');
        $renderer->render(str_repeat('x', 5000) . "\n");
    }

    public function testWindowStyleNoneCollapsesTheHeaderBandInPng(): void
    {
        $none  = PngRenderer::dark()->withWindowStyle('none')->withShadow(false)->render("x\n");
        $macos = PngRenderer::dark()->withWindowStyle('macos')->withShadow(false)->render("x\n");

        $this->assertSame(
            36,
            getimagesizefromstring($macos)[1] - getimagesizefromstring($none)[1],
            'WindowStyle::None must remove the 36px header band, not leave it empty',
        );
    }

    public function testPaddingAndRadiusWithersCeilAtTheSameDoorsAsTheConstructor(): void
    {
        // The withers used to be floor-only and trusted the ctor's door for
        // the ceiling. They now clamp symmetrically like SvgRenderer's, and
        // these pins hold that shape on both ends.
        $this->assertSame(PngRenderer::MAX_PADDING, PngRenderer::dark()->withPadding(99999)->padding);
        $this->assertSame(PngRenderer::MAX_BORDER_RADIUS, PngRenderer::dark()->withBorderRadius(99999)->borderRadius);
        $this->assertSame(0, PngRenderer::dark()->withPadding(-5)->padding);
        $this->assertSame(0, PngRenderer::dark()->withBorderRadius(-5)->borderRadius);

        // Structural legs: the value pins stay green even under the old
        // lean-on-the-ctor shape (copy() re-enters the ctor door). These name
        // the withers themselves clamping their ceiling — the defect shape M5
        // described must not silently return.
        foreach (['withPadding' => 'MAX_PADDING', 'withBorderRadius' => 'MAX_BORDER_RADIUS'] as $wither => $ceiling) {
            $this->assertSame(
                1,
                preg_match(
                    '/function ' . $wither . '\(int \$[pr]\)[^;]*' . $ceiling . '[^;]*;/',
                    (string) file_get_contents(dirname(__DIR__) . '/src/PngRenderer.php'),
                ),
                $wither . ' must clamp its own ceiling instead of leaning on the ctor door',
            );
        }
    }
}

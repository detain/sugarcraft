<?php

declare(strict_types=1);

namespace SugarCraft\CodeShot\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\CodeShot\SvgRenderer;
use SugarCraft\CodeShot\Tests\Support\SvgOracle;
use SugarCraft\CodeShot\WindowStyle;

final class SvgRendererTest extends TestCase
{
    public function testEmitsSvgHeaderAndRoot(): void
    {
        $svg = SvgRenderer::dark()->render("hello world\n");
        SvgOracle::assertWellFormed($svg);
        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $svg);
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('xmlns="http://www.w3.org/2000/svg"', $svg);
        $this->assertStringEndsWith("</svg>\n", $svg);
    }

    public function testRendersTextContent(): void
    {
        $svg = SvgRenderer::dark()->render("hello\nworld");
        SvgOracle::assertWellFormed($svg);
        $this->assertStringContainsString('hello', $svg);
        $this->assertStringContainsString('world', $svg);
    }

    public function testWindowControlsRenderedByDefault(): void
    {
        $svg = SvgRenderer::dark()->render('x');
        SvgOracle::assertWellFormed($svg);
        // Three traffic-light circles.
        $this->assertSame(3, substr_count($svg, '<circle'));
    }

    public function testWithoutWindow(): void
    {
        $svg = SvgRenderer::dark()->withWindow(false)->render('x');
        SvgOracle::assertWellFormed($svg);
        $this->assertSame(0, substr_count($svg, '<circle'));
    }

    public function testWithoutShadowOmitsFilter(): void
    {
        $svg = SvgRenderer::dark()->withShadow(false)->render('x');
        SvgOracle::assertWellFormed($svg);
        $this->assertStringNotContainsString('<filter', $svg);
        $this->assertStringNotContainsString('feDropShadow', $svg);
    }

    public function testLineNumbersAddGutter(): void
    {
        $svg = SvgRenderer::dark()->withLineNumbers(true)->render("a\nb\nc");
        SvgOracle::assertWellFormed($svg);
        // Each line number rendered as text with the lineNumber colour.
        $this->assertStringContainsString('1', $svg);
        $this->assertStringContainsString('2', $svg);
        $this->assertStringContainsString('3', $svg);
    }

    public function testThemePresetsAppearInOutput(): void
    {
        // Each preset produces a different background colour in the frame.
        $bgs = [
            SvgRenderer::dark()->theme->background       => SvgRenderer::dark(),
            SvgRenderer::light()->theme->background      => SvgRenderer::light(),
            SvgRenderer::dracula()->theme->background    => SvgRenderer::dracula(),
            SvgRenderer::tokyoNight()->theme->background => SvgRenderer::tokyoNight(),
            SvgRenderer::nord()->theme->background       => SvgRenderer::nord(),
        ];
        $this->assertCount(5, $bgs);
        foreach ($bgs as $bg => $renderer) {
            $svg = $renderer->render('x');
            SvgOracle::assertWellFormed($svg, 'theme preset ' . $bg);
            $this->assertStringContainsString('fill="' . $bg . '"', $svg);
        }
    }

    public function testAnsiForegroundBecomesTspanFill(): void
    {
        $svg = SvgRenderer::dark()->render("\x1b[31mred\x1b[0m text");
        SvgOracle::assertWellFormed($svg);
        // ANSI 31 = bright red @ #cd0000.
        $this->assertStringContainsString('fill="#cd0000"', $svg);
        $this->assertStringContainsString('red', $svg);
        $this->assertStringContainsString('text', $svg);
    }

    public function testAnsiBoldEmitsFontWeight(): void
    {
        $svg = SvgRenderer::dark()->render("\x1b[1mloud\x1b[0m");
        SvgOracle::assertWellFormed($svg);
        $this->assertStringContainsString('font-weight="bold"', $svg);
    }

    public function testAnsiBackgroundBecomesRectFill(): void
    {
        // ANSI 44 = blue background
        $svg = SvgRenderer::dark()->render("\x1b[44mblue background\x1b[0m text");
        SvgOracle::assertWellFormed($svg);
        // ANSI 44 = #0000ee (blue)
        $this->assertStringContainsString('fill="#0000ee"', $svg);
        $this->assertStringContainsString('blue background', $svg);
    }

    public function testTrueColorAnsi(): void
    {
        $svg = SvgRenderer::dark()->render("\x1b[38;2;255;128;0morange\x1b[0m");
        SvgOracle::assertWellFormed($svg);
        $this->assertStringContainsString('fill="#ff8000"', $svg);
    }

    public function testHtmlEscapeOnContent(): void
    {
        $svg = SvgRenderer::dark()->withWindow(false)->render('<script>');
        SvgOracle::assertWellFormed($svg);
        // The literal text must be XML-escaped — never the raw `<script>`.
        $this->assertStringContainsString('&lt;script&gt;', $svg);
        $this->assertStringNotContainsString('<script>', $svg);
    }

    public function testEmptyInputRendersMinimalFrame(): void
    {
        $svg = SvgRenderer::dark()->withWindow(false)->render("");
        SvgOracle::assertWellFormed($svg);
        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $svg);
        $this->assertStringEndsWith("</svg>\n", $svg);
        $this->assertStringContainsString('fill="#0d1117"', $svg);
    }

    public function testAccessibleTitleAndDescriptionAreEmitted(): void
    {
        $svg = SvgRenderer::dark()->render('x');
        SvgOracle::assertWellFormed($svg);
        $this->assertStringContainsString('role="img"', $svg);
        $this->assertStringContainsString('<title>candy-codeshot screenshot</title>', $svg);
        $this->assertStringContainsString('<desc>Code screenshot rendered by candy-codeshot.</desc>', $svg);
    }

    public function testWithTitleOverridesTheDefaultName(): void
    {
        $svg = SvgRenderer::dark()->withTitle('example.php')->render('x');
        SvgOracle::assertWellFormed($svg);
        $this->assertStringContainsString('<title>example.php</title>', $svg);
    }

    public function testInlineEmbedsDoNotShareAccessibleNameAnchors(): void
    {
        // Two renders embedded inline in one document must not collide on
        // fixed anchor ids or aria wiring: an aria-labelledby pointing at the
        // literal 'candy-codeshot-title' gave every SVG after the first the
        // first render's name. Each render names itself through its child
        // <title>, so the outputs carry no shared anchors at all.
        $one = SvgRenderer::dark()->withTitle('a.php')->render('x');
        $two = SvgRenderer::dark()->withTitle('b.php')->render('y');
        SvgOracle::assertWellFormed($one, 'first embedded render');
        SvgOracle::assertWellFormed($two, 'second embedded render');

        $this->assertStringNotContainsString('aria-labelledby', $one);
        $this->assertStringNotContainsString('aria-labelledby', $two);
        $this->assertStringNotContainsString('id="candy-codeshot-title"', $one . $two);
        $this->assertStringNotContainsString('id="candy-codeshot-desc"', $one . $two);
        $this->assertStringContainsString('<title>a.php</title>', $one);
        $this->assertStringContainsString('<title>b.php</title>', $two);
    }

    public function testSquareChromeBarsAreClippedToTheFrame(): void
    {
        $svg  = SvgRenderer::dark()->withWindowStyle(WindowStyle::WindowsTerminal)->render('x');
        $mac  = SvgRenderer::dark()->render('x');
        SvgOracle::assertWellFormed($svg, 'WindowsTerminal chrome');
        SvgOracle::assertWellFormed($mac, 'macos chrome');
        $this->assertStringContainsString('<clipPath id="frame">', $svg);
        $this->assertStringContainsString('clip-path="url(#frame)"', $svg);
        $this->assertStringNotContainsString('clipPath', $mac);
    }

    public function testTabbedLineSharesWidthWithItsSpaceExpandedTwin(): void
    {
        // SgrStateHandler pads every tab to the next 8-cell stop, so a tabbed
        // line and its space-expanded twin paint at the same columns and must
        // be framed the same width. Before the tab-aware measure, the tabbed
        // line budgeted 4 cells per tab (28 here) against the twin's 40 and
        // rendered a frame that clipped it.
        $renderer = SvgRenderer::dark()
            ->withWindow(false)->withShadow(false)->withLineNumbers(false)->withPadding(0);
        $tabbed = $renderer->render("x\tx\tx\tx\tyyyyyyyy");
        $twin   = $renderer->render('x       x       x       x       yyyyyyyy');
        SvgOracle::assertWellFormed($tabbed, 'tabbed frame');
        SvgOracle::assertWellFormed($twin, 'space-expanded twin frame');
        $tabbedWidth = self::rootWidth($tabbed);
        $twinWidth   = self::rootWidth($twin);

        $this->assertSame($twinWidth, $tabbedWidth);
    }

    public function testPaddingAndRadiusWithersCeilAtTheSameDoorsAsTheConstructor(): void
    {
        // Symmetry pin for the sibling renderer: both renderers floor at zero
        // and ceiling at MAX_* in their withers (PngRenderer's withers were
        // floor-only until step 11).
        $this->assertSame(SvgRenderer::MAX_PADDING, SvgRenderer::dark()->withPadding(99999)->padding);
        $this->assertSame(SvgRenderer::MAX_BORDER_RADIUS, SvgRenderer::dark()->withBorderRadius(99999)->borderRadius);
        $this->assertSame(0, SvgRenderer::dark()->withPadding(-5)->padding);
        $this->assertSame(0, SvgRenderer::dark()->withBorderRadius(-5)->borderRadius);

        // Structural legs (mirror of PngRendererTest): the wither bodies, not
        // the ctor door, must carry the ceiling clamp on this renderer too.
        foreach (['withPadding' => 'MAX_PADDING', 'withBorderRadius' => 'MAX_BORDER_RADIUS'] as $wither => $ceiling) {
            $this->assertSame(
                1,
                preg_match(
                    '/function ' . $wither . '\(int \$[pr]\)[^;]*' . $ceiling . '[^;]*;/',
                    (string) file_get_contents(dirname(__DIR__) . '/src/SvgRenderer.php'),
                ),
                $wither . ' must clamp its own ceiling instead of leaning on the ctor door',
            );
        }
    }

    // -------------------------------------------------------------------------
    // cs-04 escape sweep: DEL/C1, XML-illegal scalars, invalid-UTF-8
    // conversion — the byte-scan half of the contract; the structural half
    // (the document must parse, whatever survived the sweep) rides the
    // cs-03 oracle.
    // -------------------------------------------------------------------------

    public function testSweptBytesNeverReachTheDocument(): void
    {
        // Text channel AND the title channel (the cat-channel filename path
        // that used to flow unswept, P2 found c2 9b in). Sweep, not emit.
        $svg = SvgRenderer::dark()
            ->withTitle("file\xC2\x9Bname\x7f")
            ->withWindow(false)
            ->render("a\x7fb\xC2\x9Bc\n");

        SvgOracle::assertWellFormed($svg);
        $this->assertNoIllegalControlBytes($svg);
        $this->assertStringContainsString('filename', $svg);
        $this->assertStringContainsString('abc', $svg);
    }

    public function testXmlIllegalNoncharactersAreDropped(): void
    {
        // U+FDD0-U+FDEF noncharacters and U+FFFE/FFFF are well-formed UTF-8
        // but libxml rejects them, killing whole documents (-015).
        $svg = SvgRenderer::dark()
            ->withTitle("t\u{FDD0}x\u{FFFE}")
            ->withWindow(false)
            ->render("line\u{FFFF}end\n");

        // This is the exact -015 class the substring-only era shipped
        // through: green string pins on a document nothing can parse.
        SvgOracle::assertWellFormed($svg);
        $this->assertNoIllegalControlBytes($svg);
        $this->assertStringContainsString('tx', $svg);
        $this->assertStringContainsString('lineend', $svg);
    }

    public function testInvalidUtf8TitleConvertsHonestlyInsteadOfVanishing(): void
    {
        // -050: htmlspecialchars silently empties non-empty invalid UTF-8.
        // The conversion keeps the valid survivors either side of the junk
        // (the M12 rule the segment channel already uses).
        $svg = SvgRenderer::dark()
            ->withTitle("pre\xFF\xFEpost")
            ->withWindow(false)
            ->render("keep\n");

        SvgOracle::assertWellFormed($svg);
        $this->assertStringContainsString('pre??post', $svg);
    }

    public function testOversizedSvgGeometryIsRefused(): void
    {
        // cs-08 (-011): the 5 MB single-line repro emitted width="42000048"
        // — a document no downstream renderer can paint. The ceiling is the
        // PNG engine's 40 Mpx number; refusal happens in the layout
        // arithmetic, before the per-line loop spends any CPU.
        $huge = str_repeat('x', 40_000) . "\n"; // ~336k px wide x ~131 tall ≈ 44M px
        $this->expectException(\SugarCraft\CodeShot\Support\OutputTooLarge::class);
        $this->expectExceptionMessageMatches('/SVG output too large \(\d+ x \d+ = \d+ pixels; cap 40000000\)/');
        SvgRenderer::dark()->withWindow(false)->render($huge);
    }

    public function testGeometryCeilingCarriesTypedFields(): void
    {
        // candy-codeshot-021 (cs-16): the CLI localizes this refusal from the
        // exception's typed fields, not from its English message — so the
        // fields must be exact. (cs-31 discipline: reads, no re-derivation.)
        $huge = str_repeat('x', 40_000) . "\n";
        try {
            SvgRenderer::dark()->withWindow(false)->render($huge);
            $this->fail('an over-cap geometry must not render');
        } catch (\SugarCraft\CodeShot\Support\OutputTooLarge $e) {
            $this->assertSame('SVG', $e->format);
            $this->assertSame(40_000_000, $e->cap);
            $this->assertGreaterThan(40_000_000, $e->pixels);
            $this->assertSame($e->width * $e->height, $e->pixels);
            $this->assertMatchesRegularExpression('/^SVG output too large \(\d+ x \d+ = \d+ pixels; cap 40000000\)\. Reduce content, padding or line count\.$/', $e->getMessage());
        }
    }

    public function testOversizedRegularFontThrowsTypedFontTooLarge(): void
    {
        // The font leg of the same pair: real TTF magic so the size door is
        // what refuses, and the fields the CLI interpolates must be honest.
        $path = tempnam(sys_get_temp_dir(), 'cs16font') . '.ttf';
        $fh = fopen($path, 'wb');
        $this->assertNotFalse($fh);
        fwrite($fh, "\x00\x01\x00\x00");
        ftruncate($fh, 33_554_433);
        fclose($fh);
        try {
            try {
                SvgRenderer::dark()->withFont($path)->withWindow(false)->render("x\n");
                $this->fail('an over-cap font must not embed');
            } catch (\SugarCraft\CodeShot\Support\FontTooLarge $e) {
                $this->assertSame(33_554_432, $e->cap);
                $this->assertSame('32 MiB', $e->capLabel());
                $this->assertSame(33_554_433, $e->size);
                $this->assertSame($path, $e->path);
            }
        } finally {
            unlink($path);
        }
    }

    public function testCeilingDoesNotEatLegitimateScreenshots(): void
    {
        // A wide-ish real frame (120 cols x 200 lines ≈ 4M px) must still
        // render under the same ceiling that refuses 42M px.
        $text = str_repeat(str_repeat('y', 120) . "\n", 200);
        $svg  = SvgRenderer::dark()->render($text);
        SvgOracle::assertWellFormed($svg, 'a legitimate large frame');
        $this->assertStringContainsString('<svg', $svg);
    }

    private function assertNoIllegalControlBytes(string $svg): void
    {
        // Golden byte-scan: the swept bytes and the illegal scalars must
        // never appear in a rendered document, whatever entered the input.
        // One home with the injection suite: SvgOracle.
        SvgOracle::assertNoSurvivorBytes($svg);
    }

    private static function rootWidth(string $svg): string
    {
        $count = preg_match('/<svg\b[^>]*\bwidth="([^"]+)"/', $svg, $matches);
        self::assertSame(1, $count, 'root svg must carry a width attribute');

        return $matches[1];
    }
}

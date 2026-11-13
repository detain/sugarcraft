<?php

declare(strict_types=1);

namespace SugarCraft\CodeShot\Tests;

use SugarCraft\CodeShot\SvgRenderer;
use SugarCraft\CodeShot\Theme;
use SugarCraft\CodeShot\WindowStyle;
use PHPUnit\Framework\TestCase;

final class SvgRendererTest extends TestCase
{
    public function testEmitsSvgHeaderAndRoot(): void
    {
        $svg = SvgRenderer::dark()->render("hello world\n");
        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $svg);
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('xmlns="http://www.w3.org/2000/svg"', $svg);
        $this->assertStringEndsWith("</svg>\n", $svg);
    }

    public function testRendersTextContent(): void
    {
        $svg = SvgRenderer::dark()->render("hello\nworld");
        $this->assertStringContainsString('hello', $svg);
        $this->assertStringContainsString('world', $svg);
    }

    public function testWindowControlsRenderedByDefault(): void
    {
        $svg = SvgRenderer::dark()->render('x');
        // Three traffic-light circles.
        $this->assertSame(3, substr_count($svg, '<circle'));
    }

    public function testWithoutWindow(): void
    {
        $svg = SvgRenderer::dark()->withWindow(false)->render('x');
        $this->assertSame(0, substr_count($svg, '<circle'));
    }

    public function testWithoutShadowOmitsFilter(): void
    {
        $svg = SvgRenderer::dark()->withShadow(false)->render('x');
        $this->assertStringNotContainsString('<filter', $svg);
        $this->assertStringNotContainsString('feDropShadow', $svg);
    }

    public function testLineNumbersAddGutter(): void
    {
        $svg = SvgRenderer::dark()->withLineNumbers(true)->render("a\nb\nc");
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
            $this->assertStringContainsString('fill="' . $bg . '"', $svg);
        }
    }

    public function testAnsiForegroundBecomesTspanFill(): void
    {
        $svg = SvgRenderer::dark()->render("\x1b[31mred\x1b[0m text");
        // ANSI 31 = bright red @ #cd0000.
        $this->assertStringContainsString('fill="#cd0000"', $svg);
        $this->assertStringContainsString('red', $svg);
        $this->assertStringContainsString('text', $svg);
    }

    public function testAnsiBoldEmitsFontWeight(): void
    {
        $svg = SvgRenderer::dark()->render("\x1b[1mloud\x1b[0m");
        $this->assertStringContainsString('font-weight="bold"', $svg);
    }

    public function testTrueColorAnsi(): void
    {
        $svg = SvgRenderer::dark()->render("\x1b[38;2;255;128;0morange\x1b[0m");
        $this->assertStringContainsString('fill="#ff8000"', $svg);
    }

    public function testHtmlEscapeOnContent(): void
    {
        $svg = SvgRenderer::dark()->withWindow(false)->render('<script>');
        // The literal text must be XML-escaped — never the raw `<script>`.
        $this->assertStringContainsString('&lt;script&gt;', $svg);
        $this->assertStringNotContainsString('<script>', $svg);
    }

    // -------------------------------------------------------------------------
    // cs-04 escape sweep: DEL/C1, XML-illegal scalars, invalid-UTF-8
    // conversion (interim pins until cs-03 formalises the oracle).
    // -------------------------------------------------------------------------

    public function testSweptBytesNeverReachTheDocument(): void
    {
        // Text channel AND the title channel (the cat-channel filename path
        // that used to flow unswept, P2 found c2 9b in). Sweep, not emit.
        $svg = SvgRenderer::dark()
            ->withTitle("file\xC2\x9Bname\x7f")
            ->withWindow(false)
            ->render("a\x7fb\xC2\x9Bc\n");

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

        $this->assertStringContainsString('pre??post', $svg);
    }

    public function testOversizedSvgGeometryIsRefused(): void
    {
        // cs-08 (-011): the 5 MB single-line repro emitted width="42000048"
        // — a document no downstream renderer can paint. The ceiling is the
        // PNG engine's 40 Mpx number; refusal happens in the layout
        // arithmetic, before the per-line loop spends any CPU.
        $huge = str_repeat('x', 40_000) . "\n"; // ~336k px wide x ~131 tall ≈ 44M px
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/SVG output too large \(\d+ x \d+ = \d+ pixels; cap 40000000\)/');
        SvgRenderer::dark()->withWindow(false)->render($huge);
    }

    public function testCeilingDoesNotEatLegitimateScreenshots(): void
    {
        // A wide-ish real frame (120 cols x 200 lines ≈ 4M px) must still
        // render under the same ceiling that refuses 42M px.
        $text = str_repeat(str_repeat('y', 120) . "\n", 200);
        $svg = SvgRenderer::dark()->render($text);
        $this->assertStringContainsString('<svg', $svg);
    }

    private function assertNoIllegalControlBytes(string $svg): void
    {
        // Golden byte-scan: the swept bytes and the illegal scalars must
        // never appear in a rendered document, whatever entered the input.
        $this->assertSame(0, preg_match('/[\x7f]|\xC2[\x80-\x9F]/', $svg), 'DEL/C1 byte survived into the document');
        $this->assertSame(0, preg_match('/\xEF\xBF[\xBE\xBF]/', $svg), 'U+FFFE/FFFF survived');
        $this->assertSame(0, preg_match('/\xEF\xB7[\x90-\xAF]/', $svg), 'U+FDD0-FDEF noncharacter survived');
    }

    public function testAnsiBackgroundBecomesRectFill(): void
    {
        // ANSI 44 = blue background
        $svg = SvgRenderer::dark()->render("\x1b[44mblue background\x1b[0m text");
        // ANSI 44 = #0000ee (blue)
        $this->assertStringContainsString('fill="#0000ee"', $svg);
        $this->assertStringContainsString('blue background', $svg);
    }

    public function testEmptyInputRendersMinimalFrame(): void
    {
        $svg = SvgRenderer::dark()->withWindow(false)->render("");
        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $svg);
        $this->assertStringEndsWith("</svg>\n", $svg);
        $this->assertStringContainsString('fill="#0d1117"', $svg);
    }
    public function testAccessibleTitleAndDescriptionAreEmitted(): void
    {
        $svg = SvgRenderer::dark()->render('x');
        $this->assertStringContainsString('role="img"', $svg);
        $this->assertStringContainsString('<title>candy-codeshot screenshot</title>', $svg);
        $this->assertStringContainsString('<desc>Code screenshot rendered by candy-codeshot.</desc>', $svg);
    }

    public function testWithTitleOverridesTheDefaultName(): void
    {
        $svg = SvgRenderer::dark()->withTitle('example.php')->render('x');
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

        $this->assertStringNotContainsString('aria-labelledby', $one);
        $this->assertStringNotContainsString('aria-labelledby', $two);
        $this->assertStringNotContainsString('id="candy-codeshot-title"', $one . $two);
        $this->assertStringNotContainsString('id="candy-codeshot-desc"', $one . $two);
        $this->assertStringContainsString('<title>a.php</title>', $one);
        $this->assertStringContainsString('<title>b.php</title>', $two);
    }

    public function testSquareChromeBarsAreClippedToTheFrame(): void
    {
        $svg = SvgRenderer::dark()->withWindowStyle(WindowStyle::WindowsTerminal)->render('x');
        $this->assertStringContainsString('<clipPath id="frame">', $svg);
        $this->assertStringContainsString('clip-path="url(#frame)"', $svg);
        $this->assertStringNotContainsString('clipPath', SvgRenderer::dark()->render('x'));
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
        $tabbedWidth = self::rootWidth($renderer->render("x\tx\tx\tx\tyyyyyyyy"));
        $twinWidth   = self::rootWidth($renderer->render('x       x       x       x       yyyyyyyy'));

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

    private static function rootWidth(string $svg): string
    {
        $count = preg_match('/<svg\b[^>]*\bwidth="([^"]+)"/', $svg, $matches);
        self::assertSame(1, $count, 'root svg must carry a width attribute');

        return $matches[1];
    }
}

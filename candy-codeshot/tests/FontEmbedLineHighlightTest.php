<?php

declare(strict_types=1);

namespace SugarCraft\CodeShot\Tests;

use SugarCraft\CodeShot\SvgRenderer;
use PHPUnit\Framework\TestCase;

final class FontEmbedLineHighlightTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Font embedding
    // -------------------------------------------------------------------------

    public function testWithFontThrowsOnMissingFile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not found/');
        SvgRenderer::dark()->withFont('/nonexistent/font.ttf');
    }

    public function testWithFontSetsFontPath(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'font_');
        file_put_contents($tmp, 'fake font data');

        try {
            $r = SvgRenderer::dark()->withFont($tmp);
            $this->assertSame($tmp, $r->fontPath);
        } finally {
            unlink($tmp);
        }
    }

    public function testEmbeddedFontEmitsBase64InStyleTag(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'font_');
        file_put_contents($tmp, 'fake font data');

        try {
            $svg = SvgRenderer::dark()->withFont($tmp)->withWindow(false)->render("x\n");
            $this->assertStringContainsString('base64,', $svg);
            $this->assertStringContainsString('font-family: "embedded"', $svg);
            $this->assertStringContainsString('@font-face', $svg);
        } finally {
            unlink($tmp);
        }
    }

    public function testNoFontEmbeddedWhenPathIsNull(): void
    {
        // Vacuous-guard cure (lineage #58 origin): the old shape started from a
        // fresh dark() — where fontPath was ALREADY null — so the can't-clear
        // bug could never fail it. Set a font first, then clear it.
        $tmp = tempnam(sys_get_temp_dir(), 'font_');
        file_put_contents($tmp, 'fake font data');

        try {
            $set = SvgRenderer::dark()->withFont($tmp);
            $this->assertSame($tmp, $set->fontPath);

            $cleared = $set->withFont(null);
            $this->assertNull($cleared->fontPath);

            // Render level: the cleared renderer must embed nothing...
            $svg = $cleared->withWindow(false)->render("x\n");
            $this->assertStringNotContainsString('base64,', $svg);
            $this->assertStringNotContainsString('@font-face', $svg);

            // ...while the original still does (a clear must not retroactively
            // strip the renderer it was derived from).
            $embedded = $set->withWindow(false)->render("x\n");
            $this->assertStringContainsString('@font-face', $embedded);
        } finally {
            unlink($tmp);
        }
    }

    /** @return array<string, array{0:string, 1:?string}> */
    public static function fontMagicProvider(): array
    {
        return [
            'ttf'      => ["\x00\x01\x00\x00rest", 'font/ttf'],
            'ttf-true' => ['truerest',            'font/ttf'],
            'ttc'      => ['ttcfrest',            'font/ttf'],
            'otf'      => ['OTTOrest',            'font/otf'],
            'woff'     => ['wOFFrest',            'font/woff'],
            'woff2'    => ['wOF2rest',            'font/woff2'],
            'non-font' => ['fake font data',      null],
            'empty'    => ['',                     null],
        ];
    }

    /** @dataProvider fontMagicProvider */
    public function testSniffFontMimeMapsMagicBytes(string $blob, ?string $expected): void
    {
        $this->assertSame($expected, SvgRenderer::sniffFontMime($blob));
    }

    public function testEmbeddedFontEmitsTtfMimeForTrueTypeMagic(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'font_');
        file_put_contents($tmp, "\x00\x01\x00\x00the rest of a truetype file");

        try {
            $svg = SvgRenderer::dark()->withFont($tmp)->withWindow(false)->render("x\n");
            $this->assertStringContainsString('src: url(data:font/ttf;base64,', $svg);
            $this->assertStringNotContainsString('data:font/woff2;base64,', $svg);
        } finally {
            unlink($tmp);
        }
    }

    public function testEmbeddedFontEmitsWoff2MimeForWoff2Magic(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'font_');
        file_put_contents($tmp, 'wOF2the rest of a woff2 file');

        try {
            $svg = SvgRenderer::dark()->withFont($tmp)->withWindow(false)->render("x\n");
            // Regression: MIME was hardcoded to font/ttf regardless of format.
            $this->assertStringContainsString('src: url(data:font/woff2;base64,', $svg);
            $this->assertStringNotContainsString('data:font/ttf;base64,', $svg);
        } finally {
            unlink($tmp);
        }
    }

    public function testEmbeddedFontOverridesFontFamilyInOutput(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'font_');
        file_put_contents($tmp, 'fake font data');

        try {
            $svg = SvgRenderer::dark()->withFont($tmp)->withWindow(false)->render("x\n");
            // Font family should be "embedded" (the injected font name) not Hack.
            $this->assertStringContainsString('font-family="embedded"', $svg);
        } finally {
            unlink($tmp);
        }
    }

    // -------------------------------------------------------------------------
    // Line highlighting
    // -------------------------------------------------------------------------

    public function testWithHighlightThrowsOnZeroStart(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/start.*>=.*1/');
        SvgRenderer::dark()->withHighlight(0, 5);
    }

    public function testWithHighlightThrowsOnNegativeStart(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/start.*>=.*1/');
        SvgRenderer::dark()->withHighlight(-1, 5);
    }

    public function testWithHighlightThrowsWhenEndBeforeStart(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/end must be >= start/');
        SvgRenderer::dark()->withHighlight(5, 3);
    }

    public function testWithHighlightSingleLine(): void
    {
        $svg = SvgRenderer::dark()
            ->withHighlight(2, 2, '#ffff00')
            ->withWindow(false)
            ->render("line1\nline2\nline3\n");

        // Line 2 should have a highlight rect.
        $this->assertStringContainsString('fill="#ffff00"', $svg);
    }

    public function testWithHighlightRangeOfLines(): void
    {
        $svg = SvgRenderer::dark()
            ->withHighlight(2, 4, '#fffbe6')
            ->withWindow(false)
            ->render("line1\nline2\nline3\nline4\nline5\n");

        // Lines 2, 3, and 4 are in range — one rect per line.
        $this->assertSame(3, substr_count($svg, 'fill="#fffbe6"'));
    }

    public function testWithHighlightDefaultsToThemeColour(): void
    {
        // Omitting the colour stores null; render resolves it from the theme's
        // own highlight band (Theme::$highlight), falling back to #fffbe6 only
        // for themes that carry no highlight slot.
        $r = SvgRenderer::dark()->withHighlight(1, 1);
        $this->assertNull($r->highlight['color']);

        $dark = SvgRenderer::dark()
            ->withHighlight(1, 1)
            ->withWindow(false)
            ->render("line\n");
        $this->assertStringContainsString('fill="#2d333b"', $dark);

        $light = SvgRenderer::light()
            ->withHighlight(1, 1)
            ->withWindow(false)
            ->render("line\n");
        $this->assertStringContainsString('fill="#fff9c4"', $light);
    }

    public function testNoHighlightRectWhenHighlightNotSet(): void
    {
        // Without calling withHighlight, no highlight rects should be emitted.
        $svg = SvgRenderer::dark()
            ->withWindow(false)
            ->render("line1\nline2\nline3\n");

        $this->assertStringNotContainsString('fill="#fffbe6"', $svg);
        $this->assertStringNotContainsString('fill="#ffff00"', $svg);
    }

    public function testHighlightRectPositionAlignedWithLines(): void
    {
        // candy-codeshot-061 (cs-31) vacuous cure: the old leg admitted it
        // "can't easily test exact coordinates" and re-asserted the fill —
        // already proven above — while the coordinates are perfectly
        // parseable. Pinned geometry for line 2 (index 1) on the dark theme
        // (fontSize 14, lineHeight 1.4, padding default 24, no window/shadow):
        //   cellH = 14 * 1.4 = 19.6
        //   y     = textY0 + 1*cellH - fontSize = (24 + 14) + 19.6 - 14 = 43.6
        //   x     = padding = 24
        //   height = cellH = 19.6 ; width = longest line (5 cells) * 8.4 = 42
        $text = "line1\nline2\nline3\n";
        $svg = SvgRenderer::dark()
            ->withHighlight(2, 2, '#ffff00')
            ->withWindow(false)
            ->withLineNumbers(false)
            ->withShadow(false)
            ->withBorder(false)
            ->render($text);

        $count = preg_match_all(
            '/<rect x="(\d+)" y="([\d.]+)" width="(\d+)" height="([\d.]+)" fill="#ffff00" \/>/',
            $svg,
            $m,
        );
        $this->assertSame(1, $count, 'exactly the requested line may carry a highlight rect');
        $this->assertSame('24', $m[1][0]);
        $this->assertSame('43.60', $m[2][0]);
        $this->assertSame('42', $m[3][0]);
        $this->assertSame('19.60', $m[4][0]);
    }

    public function testHighlightAndFontEmbeddingTogether(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'font_');
        file_put_contents($tmp, 'fake font data');

        try {
            $svg = SvgRenderer::dark()
                ->withFont($tmp)
                ->withHighlight(1, 3, '#ff0000')
                ->withWindow(false)
                ->render("a\nb\nc\n");

            $this->assertStringContainsString('base64,', $svg);
            $this->assertStringContainsString('font-family: "embedded"', $svg);
            $this->assertStringContainsString('fill="#ff0000"', $svg);
        } finally {
            unlink($tmp);
        }
    }
}

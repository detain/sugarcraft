<?php

declare(strict_types=1);

namespace SugarCraft\CodeShot\Tests;

use SugarCraft\CodeShot\Theme\FlatPaletteThemeLoader;
use PHPUnit\Framework\TestCase;

final class FlatPaletteThemeLoaderTest extends TestCase
{
    public function testLoadThrowsOnMissingFile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not found/');
        FlatPaletteThemeLoader::load('/nonexistent/theme.json');
    }

    public function testLoadThrowsOnInvalidJson(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'flatp_');
        file_put_contents($tmp, 'not json');
        try {
            $this->expectException(\JsonException::class);
            FlatPaletteThemeLoader::load($tmp);
        } finally {
            unlink($tmp);
        }
    }

    public function testFromArrayProducesTheme(): void
    {
        $data = [
            'background' => '#1e1e1e',
            'foreground' => '#d4d4d4',
            'colors' => [
                'comment' => '#6a9955',
                'keyword' => '#569cd6',
            ],
        ];

        $theme = FlatPaletteThemeLoader::fromArray($data);

        $this->assertSame('#1e1e1e', $theme->background);
        $this->assertSame('#d4d4d4', $theme->foreground);
        // cs-07: tokens land on syntax slots, never on chrome. The old pin
        // asserted keyword -> windowRed, the wrong map this step removed.
        $this->assertSame('#569cd6', $theme->keyword);
        $this->assertSame('#6a9955', $theme->comment);
        // A theme with no lineNumberColor lets the comment colour back the
        // gutter — that fallback survives; the chrome buttons do not move.
        $this->assertSame('#6a9955', $theme->lineNumber);
        $this->assertSame('#ff5f56', $theme->windowRed);
        $this->assertSame('#ffbd2e', $theme->windowYellow);
        $this->assertSame('#27c93f', $theme->windowGreen);
    }

    public function testNormalizes3DigitHex(): void
    {
        $data = [
            'background' => '#111',
            'foreground' => '#aaa',
            'colors' => [],
        ];

        $theme = FlatPaletteThemeLoader::fromArray($data);

        $this->assertSame('#111111', $theme->background);
        $this->assertSame('#aaaaaa', $theme->foreground);
    }

    public function testNormalizes8DigitHexTo6(): void
    {
        $data = [
            'background' => '#1e1e1ecc',
            'foreground' => '#d4d4d4ff',
            'colors' => [],
        ];

        $theme = FlatPaletteThemeLoader::fromArray($data);

        $this->assertSame('#1e1e1e', $theme->background);
        $this->assertSame('#d4d4d4', $theme->foreground);
    }

    public function testDefaultsWhenColorsMissing(): void
    {
        $data = [
            'colors' => [],
        ];

        $theme = FlatPaletteThemeLoader::fromArray($data);

        $this->assertSame('#0d1117', $theme->background);
        $this->assertSame('#c9d1d9', $theme->foreground);
    }

    public function testMapsColorsToThemeProperties(): void
    {
        $data = [
            'background' => '#282a36',
            'foreground' => '#f8f8f2',
            'colors' => [
                'comment' => '#6272a4',
                'keyword' => '#ff79c6',
                'string'  => '#f1fa8c',
            ],
        ];

        $theme = FlatPaletteThemeLoader::fromArray($data);

        // cs-07 corrected mapping: tokens fill syntax slots; the gutter only
        // backs off to the comment colour, and the chrome buttons stay home.
        $this->assertSame('#6272a4', $theme->lineNumber);
        $this->assertSame('#ff79c6', $theme->keyword);
        $this->assertSame('#f1fa8c', $theme->string);
        $this->assertSame('#ff5f56', $theme->windowRed);
        $this->assertSame('#ffbd2e', $theme->windowYellow);
        $this->assertSame('#27c93f', $theme->windowGreen);
    }

    public function testLoadFromFileRoundTrip(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'flatp_');
        $json = json_encode([
            'background' => '#282a36',
            'foreground' => '#f8f8f2',
            'colors' => [],
        ], JSON_THROW_ON_ERROR);
        file_put_contents($tmp, $json);

        try {
            $theme = FlatPaletteThemeLoader::load($tmp);
            $this->assertSame('#282a36', $theme->background);
            $this->assertSame('#f8f8f2', $theme->foreground);
        } finally {
            unlink($tmp);
        }
    }

    public function testFullFlatPaletteWithAllColors(): void
    {
        $data = [
            'background' => '#1a1b26',
            'foreground' => '#a9b1d6',
            'colors' => [
                'comment' => '#565f89',
                'keyword' => '#f7768e',
                'string' => '#9ece6a',
                'number' => '#ff9e64',
                'variable' => '#9ece6a',
                'constant' => '#ff9e64',
                'operator' => '#89ddff',
                'type' => '#e0af68',
                'class' => '#e0af68',
                'function' => '#7dcfff',
                'punctuation' => '#89ddff',
                'attribute' => '#e0af68',
                'tag' => '#f7768e',
                'error' => '#f7768e',
            ],
        ];

        $theme = FlatPaletteThemeLoader::fromArray($data);
        $this->assertSame('#1a1b26', $theme->background);
        $this->assertSame('#a9b1d6', $theme->foreground);
        $this->assertSame('#565f89', $theme->lineNumber);
        // cs-07: every scope lands on its syntax slot. Where several names
        // fold onto one slot, the most-specific first entry of TOKEN_MAP
        // wins: keyword over tag/error, number over constant, type over
        // class/function/attribute. Chrome is untouched by tokens.
        $this->assertSame('#f7768e', $theme->keyword);
        $this->assertSame('#9ece6a', $theme->string);
        $this->assertSame('#ff9e64', $theme->number);
        $this->assertSame('#565f89', $theme->comment);
        $this->assertSame('#e0af68', $theme->type);
        $this->assertSame('#ff5f56', $theme->windowRed);
        $this->assertSame('#ffbd2e', $theme->windowYellow);
        $this->assertSame('#27c93f', $theme->windowGreen);
    }

    public function testPaletteTopLevelFontFamilyPassesThroughTheSameCharsetDoor(): void
    {
        //a flat palette carries fontFamily as a top-level key (the VS Code loader reads it from a
        // tokenColor's settings), but both loaders hand it to the identical
        // sanitizeFontFamily charset door before it can reach an SVG attribute.
        $load = static fn (mixed $family): \SugarCraft\CodeShot\Theme => FlatPaletteThemeLoader::fromArray([
            'background'  => '#0d1117',
            'foreground'  => '#c9d1d9',
            'colors'      => [],
            'fontFamily'  => $family,
        ]);

        $this->assertSame(
            'Hack, "JetBrains Mono", Menlo, Consolas, monospace',
            $load('"/></style><circle onload="x"/>')->fontFamily,
            'markup in a theme fontFamily must never reach the renderer',
        );
        $this->assertSame(
            'Hack, "JetBrains Mono", Menlo, Consolas, monospace',
            $load("Iosevka\x07term")->fontFamily,
            'a control byte is outside the admitted charset',
        );
        $this->assertSame('Iosevka Term, monospace', $load('Iosevka Term, monospace')->fontFamily);
    }

    public function testNonStringFontFamilyShapesFallBackInsteadOfFatalising(): void
    {
        // sanitizeFontFamily takes a typed string; a hostile theme carrying
        // fontFamily as a list or a number used to fatal the loader with a
        // strict_types TypeError. Non-strings now fall back to the house
        // default — theme files are not trusted, and refusal must stay a
        // fallback, never a crash or a cast onto the wire.
        $shapes = [
            'list'  => ['Hack', 'monospace'],
            'int'   => 42,
            'float' => 1.5,
            'bool'  => true,
            'null'  => null,
        ];
        foreach ($shapes as $label => $family) {
            $theme = FlatPaletteThemeLoader::fromArray([
                'background' => '#0d1117',
                'foreground' => '#c9d1d9',
                'colors'     => [],
                'fontFamily' => $family,
            ]);
            $this->assertSame(
                'Hack, "JetBrains Mono", Menlo, Consolas, monospace',
                $theme->fontFamily,
                "a {$label}-shaped fontFamily must fall back, never fatal or cast",
            );
        }
    }

    public function testLoadRefusesFifoWithoutBlocking(): void
    {
        // candy-codeshot-049 (cs-27): file_exists admits FIFOs, and the old
        // loader ran file_get_contents on one — with no writer attached that
        // read blocks forever, hanging the process that merely wanted to
        // reject a bad theme path. The is_file door answers instantly.
        if (!function_exists('posix_mkfifo')) {
            $this->markTestSkipped('posix_mkfifo unavailable');
        }
        $fifo = sys_get_temp_dir() . '/cs27-flatp-' . bin2hex(random_bytes(6)) . '.fifo';
        if (!posix_mkfifo($fifo, 0600)) {
            $this->markTestSkipped('could not create FIFO (named pipes not permitted here)');
        }
        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessageMatches('/not a regular file/');
            FlatPaletteThemeLoader::load($fifo);
        } finally {
            unlink($fifo);
        }
    }

    public function testVsCodeEditorBackgroundColorKeyIsIgnored(): void
    {
        // candy-codeshot-026 (cs-18): the old fallback read `editorBackgroundColor`,
        // a VS Code key the flat-palette format has never carried — a palette
        // file must take the VS Code key literally (border defaults, or the
        // in-schema `background` fallback), never borrow another editor's
        // namespace. Re-adding the leak reddens this pin.
        $theme = FlatPaletteThemeLoader::fromArray([
            'border'                => '#30363d',
            'editorBackgroundColor' => '#123456',
        ]);
        $this->assertSame('#30363d', $theme->border);

        $theme = FlatPaletteThemeLoader::fromArray([
            'editorBackgroundColor' => '#123456',
        ]);
        $this->assertSame('#30363d', $theme->border, 'a lone VS Code key must not move the border');
    }

    public function testBorderFallsBackToInSchemaBackgroundKey(): void
    {
        // With no `border` the fallback now reads the palette's own
        // `background` key (the honest sibling of the removed VS Code leak);
        // an invalid background still falls to the documented default.
        $theme = FlatPaletteThemeLoader::fromArray([
            'background' => '#282a36',
        ]);
        $this->assertSame('#282a36', $theme->border);

        $theme = FlatPaletteThemeLoader::fromArray([
            'background' => 'rebeccapurple',
        ]);
        $this->assertSame('#30363d', $theme->border);
    }
}

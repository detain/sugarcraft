<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Tape;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Encode\PhpGifEncoder;
use SugarCraft\Vcr\Encode\TapeToGif;
use SugarCraft\Vcr\Raster\GdRasterizer;
use SugarCraft\Vcr\Render\Renderer;
use SugarCraft\Vcr\Tape\Compiler;
use SugarCraft\Vcr\Tape\InlineTheme;
use SugarCraft\Vcr\Tape\Lexer;
use SugarCraft\Vcr\Tape\Parser;
use SugarCraft\Vterm\Theme;

/**
 * VCR-S53: an inline `Set Theme {…}` JSON object must BECOME the theme — its
 * colours reach the raster door instead of degrading to the TokyoNight
 * fallback that an unrecognized name triggers.
 */
final class JsonThemeTest extends TestCase
{
    public function testInlineJsonIsRecognisedAndNamedThemesAreNot(): void
    {
        self::assertTrue(InlineTheme::looksLikeJson('{"background":"#1a1b26"}'));
        self::assertTrue(InlineTheme::looksLikeJson("  { 'red':'#f00' } "));
        self::assertFalse(InlineTheme::looksLikeJson('TokyoNight'));
        self::assertFalse(InlineTheme::looksLikeJson('Dracula'));
    }

    public function testFullColourSetBecomesThePalette(): void
    {
        $theme = InlineTheme::fromJson(json_encode([
            'background' => '#1a1b26',
            'foreground' => '#c0caf5',
            'black' => '#15161e',
            'red' => '#f7768e',
            'green' => '#9ece6a',
            'yellow' => '#e0af68',
            'blue' => '#7aa2f7',
            'magenta' => '#bb9af7',
            'cyan' => '#7dcfff',
            'white' => '#a9b1d6',
            'brightBlack' => '#414868',
            'brightRed' => '#ff0000',
            'brightGreen' => '#00ff00',
            'brightYellow' => '#ffff00',
            'brightBlue' => '#0000ff',
            'brightMagenta' => '#ff00ff',
            'brightCyan' => '#00ffff',
            'brightWhite' => '#ffffff',
        ], JSON_THROW_ON_ERROR));

        self::assertSame(0x1a1b26, $theme->color(0), 'background lands in slot 0');
        self::assertSame(0, $theme->defaultBg);
        self::assertSame(0xc0caf5, $theme->color(7), 'foreground overrides the white slot it shares');
        self::assertSame(7, $theme->defaultFg);
        self::assertSame(0xf7768e, $theme->color(1));
        self::assertSame(0x414868, $theme->color(8));
        self::assertSame(0x00ffff, $theme->color(14));
    }

    public function testSparseThemeLeavesOmittedSlotsAtStandardXtermValues(): void
    {
        $theme = InlineTheme::fromJson('{"red":"#ff8800"}');

        self::assertSame(0xff8800, $theme->color(1));
        // Slot 100 is part of the 6x6x6 cube: level 0 | 55+40*4 = 215 each → 0xd7d7d7... exact xterm math:
        self::assertSame(Theme::rgb(100)[0] << 16 | Theme::rgb(100)[1] << 8 | Theme::rgb(100)[2], $theme->color(100));
    }

    public function testShortAndHashlessHexFormsParse(): void
    {
        $theme = InlineTheme::fromJson('{"red":"#f80","green":"00ff00"}');

        self::assertSame(0xff8800, $theme->color(1));
        self::assertSame(0x00ff00, $theme->color(2));
    }

    public function testInertCursorFieldsAreAcceptedButNotApplied(): void
    {
        $theme = InlineTheme::fromJson('{"background":"#101010","cursor":"#abcdef","cursorTextUpper":"#012345"}');

        self::assertSame(0x101010, $theme->color(0));
        self::assertNotSame(0xabcdef, $theme->color(1), 'cursor colour must not bleed into any ANSI slot');
    }

    public function testMalformedInputFailsClosedWithNamedErrors(): void
    {
        $cases = [
            '{"red":' => 'not valid JSON',
            '["#fff"]' => 'JSON object',
            '{"brigtRed":"#fff"}' => 'brigtRed',
            '{"red":"crimson"}' => 'is not a #RRGGBB colour',
            '{"background":42}' => 'must be a #RRGGBB string',
        ];

        foreach ($cases as $json => $needle) {
            try {
                InlineTheme::fromJson($json);
                self::fail("expected rejection for {$json}");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString($needle, $e->getMessage(), 'message names the offender for ' . $json);
            }
        }
    }

    public function testCompiledTapeCarriesTheInlineThemeIntoTheHeader(): void
    {
        $json = '{"background":"#1a1b26","foreground":"#c0caf5"}';
        $result = Compiler::parseSource("Set Theme {$json}\nType \"hi\"");
        $cassette = (new Compiler())->compile($result['ast'], '/json-theme.tape');

        self::assertSame($json, $cassette->header->theme, 'the raw object survives to the cassette header');
    }

    public function testRasterThemeDoorResolvesInlineColours(): void
    {
        $resolve = new \ReflectionMethod(TapeToGif::class, 'resolveTheme');
        $pipeline = new TapeToGif(new Lexer(), new Parser(), new Compiler(), new Renderer(), new GdRasterizer(), new PhpGifEncoder());

        $theme = $resolve->invoke($pipeline, '{"background":"#1a1b26","red":"#ff8800"}', false);
        self::assertSame(0x1a1b26, $theme->color(0));
        self::assertSame(0xff8800, $theme->color(1));

        $stock = $resolve->invoke($pipeline, 'Dracula', false);
        self::assertSame(Theme::dracula()->color(0), $stock->color(0), 'named themes keep working');
    }

    public function testBrokenInlineThemeStrictThrowsLaxFallsBackWithWarning(): void
    {
        $resolve = new \ReflectionMethod(TapeToGif::class, 'resolveTheme');
        $pipeline = new TapeToGif(new Lexer(), new Parser(), new Compiler(), new Renderer(), new GdRasterizer(), new PhpGifEncoder());

        try {
            $resolve->invoke($pipeline, '{"red":"crimson"}', true);
            self::fail('strict mode must surface the parse error');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('crimson', $e->getMessage());
        }

        // Lax mode keeps the legacy TokyoNight fallback, but the degradation
        // is LOUD: the warning names the failure and rides the same
        // warnings() channel every other render notice shares (VCR-COPY-05)
        // — it no longer bypasses into raw error_log().
        $log = tempnam(sys_get_temp_dir(), 'vcr-theme-log');
        self::assertIsString($log);
        $previous = ini_set('error_log', $log);
        try {
            $theme = $resolve->invoke($pipeline, '{"red":"crimson"}', false);
        } finally {
            if (is_string($previous)) {
                ini_set('error_log', $previous);
            } else {
                ini_set('error_log', '');
            }
        }

        self::assertSame(Theme::tokyoNight()->color(0), $theme->color(0), 'lax mode keeps the legacy fallback');
        $warnings = $pipeline->warnings();
        self::assertNotEmpty($warnings, 'the lax fallback must leave a warning on the channel');
        self::assertStringContainsString('is not a #RRGGBB colour', $warnings[0]);
        self::assertStringContainsString('falling back to TokyoNight', $warnings[0]);
        self::assertSame('', (string) file_get_contents($log), 'the notice must not bypass into error_log');
        @unlink($log);
    }
}

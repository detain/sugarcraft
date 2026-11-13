<?php

declare(strict_types=1);

namespace SugarCraft\CodeShot\Tests;

use SugarCraft\CodeShot\CodeHighlighter;
use SugarCraft\CodeShot\Theme\VsCodeThemeLoader;
use PHPUnit\Framework\TestCase;

final class VsCodeThemeLoaderTest extends TestCase
{
    public function testLoadThrowsOnMissingFile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not found/');
        VsCodeThemeLoader::load('/nonexistent/theme.json');
    }

    public function testLoadThrowsOnInvalidJson(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'vsc_');
        file_put_contents($tmp, '{invalid json');
        try {
            $this->expectException(\JsonException::class);
            VsCodeThemeLoader::load($tmp);
        } finally {
            unlink($tmp);
        }
    }

    public function testFromArrayProducesTheme(): void
    {
        $data = [
            'colors' => [
                'editor.background' => '#1e1e1e',
                'editor.foreground' => '#d4d4d4',
            ],
            'tokenColors' => [
                [
                    'scope' => ['comment', 'string'],
                    'settings' => [
                        'foreground' => '#6a9955',
                        'fontStyle' => 'italic',
                    ],
                ],
                [
                    'scope' => 'keyword',
                    'settings' => [
                        'foreground' => '#569cd6',
                    ],
                ],
                [
                    'scope' => 'variable',
                    'settings' => [
                        'foreground' => '#9cdcfe',
                    ],
                ],
            ],
        ];

        $theme = VsCodeThemeLoader::fromArray($data);

        $this->assertSame('#1e1e1e', $theme->background);
        $this->assertSame('#d4d4d4', $theme->foreground);
    }

    public function testNormalizes3DigitHex(): void
    {
        $data = [
            'colors' => [
                'editor.background' => '#1e1',
                'editor.foreground' => '#d4d',
            ],
            'tokenColors' => [],
        ];

        $theme = VsCodeThemeLoader::fromArray($data);

        $this->assertSame('#11ee11', $theme->background);
        $this->assertSame('#dd44dd', $theme->foreground);
    }

    public function testNormalizes8DigitHexTo6(): void
    {
        $data = [
            'colors' => [
                'editor.background' => '#1e1e1ecc',
                'editor.foreground' => '#d4d4d4ff',
            ],
            'tokenColors' => [],
        ];

        $theme = VsCodeThemeLoader::fromArray($data);

        $this->assertSame('#1e1e1e', $theme->background);
        $this->assertSame('#d4d4d4', $theme->foreground);
    }

    public function testDefaultsWhenColorsMissing(): void
    {
        $data = [
            'colors' => [],
            'tokenColors' => [],
        ];

        $theme = VsCodeThemeLoader::fromArray($data);

        $this->assertSame('#0d1117', $theme->background);
        $this->assertSame('#c9d1d9', $theme->foreground);
    }

    public function testMapsTokenColorsToThemeProperties(): void
    {
        $data = [
            'colors' => [
                'editor.background' => '#282a36',
                'editor.foreground' => '#f8f8f2',
            ],
            'tokenColors' => [
                [
                    'scope' => 'comment',
                    'settings' => ['foreground' => '#6272a4'],
                ],
                [
                    'scope' => 'keyword',
                    'settings' => ['foreground' => '#ff79c6'],
                ],
            ],
        ];

        $theme = VsCodeThemeLoader::fromArray($data);

        // The theme carries no editorLineNumber key, so the comment token
        // backs the gutter (fallback, cs-07); the keyword lands on the slot.
        $this->assertSame('#6272a4', $theme->lineNumber);
        $this->assertSame('#6272a4', $theme->comment);
        $this->assertSame('#ff79c6', $theme->keyword);
        $this->assertNull($theme->string);
        $this->assertNull($theme->number);
        $this->assertNull($theme->type);
    }

    public function testLoadFromFileRoundTrip(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'vsc_');
        $json = json_encode([
            'colors' => [
                'editor.background' => '#282a36',
                'editor.foreground' => '#f8f8f2',
            ],
            'tokenColors' => [],
        ], JSON_THROW_ON_ERROR);
        file_put_contents($tmp, $json);

        try {
            $theme = VsCodeThemeLoader::load($tmp);
            $this->assertSame('#282a36', $theme->background);
            $this->assertSame('#f8f8f2', $theme->foreground);
        } finally {
            unlink($tmp);
        }
    }

    public function testFullVsCodeThemeWithAllColors(): void
    {
        $data = [
            'colors' => [
                'editor.background' => '#1a1b26',
                'editor.foreground' => '#a9b1d6',
                'editorLineNumber.foreground' => '#565f89',
            ],
            'tokenColors' => [
                ['scope' => 'comment', 'settings' => ['foreground' => '#565f89']],
                ['scope' => 'keyword', 'settings' => ['foreground' => '#f7768e']],
                ['scope' => 'string', 'settings' => ['foreground' => '#9ece6a']],
                ['scope' => 'number', 'settings' => ['foreground' => '#ff9e64']],
                ['scope' => 'variable', 'settings' => ['foreground' => '#9ece6a']],
                ['scope' => 'constant', 'settings' => ['foreground' => '#ff9e64']],
                ['scope' => 'operator', 'settings' => ['foreground' => '#89ddff']],
                ['scope' => 'type', 'settings' => ['foreground' => '#e0af68']],
                ['scope' => 'class', 'settings' => ['foreground' => '#e0af68']],
                ['scope' => 'function', 'settings' => ['foreground' => '#7dcfff']],
                ['scope' => 'punctuation', 'settings' => ['foreground' => '#89ddff']],
                ['scope' => 'attribute', 'settings' => ['foreground' => '#e0af68']],
                ['scope' => 'tag', 'settings' => ['foreground' => '#f7768e']],
                ['scope' => 'error', 'settings' => ['foreground' => '#f7768e']],
            ],
        ];

        $theme = VsCodeThemeLoader::fromArray($data);
        $this->assertSame('#1a1b26', $theme->background);
        $this->assertSame('#a9b1d6', $theme->foreground);
        $this->assertSame('#565f89', $theme->lineNumber);
        // cs-07: tokens cascade to slots (last entry wins), chrome stays put.
        // type slot ends at 'attribute' (#e0af68): class/function/attribute
        // all fold there and function's #7dcfff is overridden by the later
        // attribute entry, exactly as VS Code would apply the array.
        $this->assertSame('#f7768e', $theme->keyword);
        $this->assertSame('#9ece6a', $theme->string);
        $this->assertSame('#ff9e64', $theme->number);
        $this->assertSame('#565f89', $theme->comment);
        $this->assertSame('#e0af68', $theme->type);
        $this->assertSame('#ff5f56', $theme->windowRed);
        $this->assertSame('#ffbd2e', $theme->windowYellow);
        $this->assertSame('#27c93f', $theme->windowGreen);
    }

    public function testKeywordScopeNeverTintsTrafficLights(): void
    {
        // The cs-07 acceptance probe: a theme whose `keyword` deliberately
        // clashes with the chrome red. Code text must take the token colour
        // and the traffic lights must refuse it — the old SCOPE_MAP carried
        // keyword => windowRed, so BOTH assertions would have failed there.
        $theme = VsCodeThemeLoader::fromArray([
            'colors' => [
                'editor.background' => '#000000',
                'editor.foreground' => '#ffffff',
            ],
            'tokenColors' => [
                ['scope' => 'keyword', 'settings' => ['foreground' => '#123456']],
            ],
        ]);

        $this->assertSame('#123456', $theme->keyword);
        $this->assertSame('#ff5f56', $theme->windowRed);

        $highlighter = CodeHighlighter::forTheme($theme);
        $this->assertSame('#123456', $highlighter->keyword);
        // rgb(18, 52, 86) = #123456, painted onto real tokenised code.
        $this->assertStringContainsString(
            "\x1b[38;2;18;52;86m",
            $highlighter->highlight('return 1;', 'php'),
        );
    }

    public function testTokenColorsCascadeLastWinsLikeVsCode(): void
    {
        // VS Code applies tokenColors top-to-bottom; a repeated scope
        // overrides itself. buildPropertyMap used to stop at the FIRST hit,
        // pinning #111111 here — the pre-cs-07 inversion of the cascade.
        $theme = VsCodeThemeLoader::fromArray([
            'colors' => [],
            'tokenColors' => [
                ['scope' => 'keyword', 'settings' => ['foreground' => '#111111']],
                ['scope' => 'storage.type', 'settings' => ['foreground' => '#222222']],
                ['scope' => 'keyword', 'settings' => ['foreground' => '#333333']],
            ],
        ]);

        $this->assertSame('#333333', $theme->keyword);
        // 'storage' is not a mapped root; it must not leak anywhere.
        $this->assertNull($theme->type);
    }

    public function testUiGutterKeyBeatsCommentToken(): void
    {
        // Chrome is sourced from UI keys only: an explicit gutter colour in
        // the colors section outranks the comment token's fallback (the old
        // code let the token override even an explicit UI key).
        $theme = VsCodeThemeLoader::fromArray([
            'colors' => ['editorLineNumber.foreground' => '#010203'],
            'tokenColors' => [
                ['scope' => 'comment', 'settings' => ['foreground' => '#040506']],
            ],
        ]);

        $this->assertSame('#010203', $theme->lineNumber);
        $this->assertSame('#040506', $theme->comment);
    }

    public function testFontFamilyWhitelistRefusesMarkupAndKeepsPlainNames(): void
    {
        // The loader echoes fontFamily straight into SVG attributes and CSS, so a
        // theme file cannot smuggle markup through it. sanitizeFontFamily is a
        // charset door: anything outside [A-Za-z0-9 ,"'-] falls back to the house
        // default (ThemeTest only ever asserted the default, leaving the door
        // itself unpinned — removing sanitizeFontFamily reddened nothing).
        $with = static fn (string $family): \SugarCraft\CodeShot\Theme => VsCodeThemeLoader::fromArray([
            'colors'      => ['editor.background' => '#0d1117', 'editor.foreground' => '#c9d1d9'],
            'tokenColors' => [['scope' => 'comment', 'settings' => ['fontFamily' => $family]]],
        ]);

        // Hostile: closing a style block and opening a script tag.
        $this->assertSame(
            'Hack, "JetBrains Mono", Menlo, Consolas, monospace',
            $with('</style><script>')->fontFamily,
            'markup in a theme fontFamily must never reach the renderer',
        );
        // Hostile edge actually shipped: an ESC byte is outside the charset.
        $this->assertSame(
            'Hack, "JetBrains Mono", Menlo, Consolas, monospace',
            $with("Fira\x1b[31mCode")->fontFamily,
        );
        // Empty is refused by the '+' quantifier, not accepted as a blank family.
        $this->assertSame(
            'Hack, "JetBrains Mono", Menlo, Consolas, monospace',
            $with('')->fontFamily,
        );
        // Positive polarity: the door is a whitelist, not a blanket reject — a
        // plainly-named family with its house punctuation rides through untouched.
        $this->assertSame("Fira Code, 'Menlo', monospace", $with("Fira Code, 'Menlo', monospace")->fontFamily);
    }

    public function testNonStringSettingsFontFamilyShapesAreDropped(): void
    {
        // The old (string) cast turned a list into "Array" and a bool into
        // "1" — both sailed through the charset door and silently emitted a
        // junk font family. Non-strings are now dropped at the property map,
        // so the house default takes over.
        $shapes = ['list' => ['Hack'], 'int' => 42, 'bool' => true];
        foreach ($shapes as $label => $family) {
            $theme = VsCodeThemeLoader::fromArray([
                'colors'      => ['editor.background' => '#0d1117', 'editor.foreground' => '#c9d1d9'],
                'tokenColors' => [['scope' => 'comment', 'settings' => ['fontFamily' => $family]]],
            ]);
            $this->assertSame(
                'Hack, "JetBrains Mono", Menlo, Consolas, monospace',
                $theme->fontFamily,
                "a {$label}-shaped settings fontFamily must be dropped, not cast",
            );
        }
    }

    // -------------------------------------------------------------------------
    // cs-11 (FUZZ-03): scalar doors — third-party JSON shapes that used to
    // escape as live TypeErrors past the documented @throws.
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{0:array<string,mixed>}>
     */
    public static function scalarSectionShapes(): array
    {
        return [
            'colors int'                => [['colors' => 42]],
            'colors string'             => [['colors' => 'x']],
            'tokenColors string'        => [['tokenColors' => 'x']],
            'tokenColors int'           => [['tokenColors' => 42]],
            'entry scalar'              => [['tokenColors' => [42]]],
            'entry string'              => [['tokenColors' => ['oops']]],
            'scope scalar'              => [['tokenColors' => [['scope' => 42, 'settings' => ['foreground' => '#112233']]]]],
            'scope element scalar'      => [['tokenColors' => [['scope' => [42, 43], 'settings' => ['foreground' => '#112233']]]]],
            'settings scalar'           => [['tokenColors' => [['scope' => 'keyword', 'settings' => 42]]]],
        ];
    }

    /**
     * @dataProvider scalarSectionShapes
     * @param array<string,mixed> $shape
     */
    public function testScalarShapesFallBackInsteadOfTypingError(array $shape): void
    {
        // No TypeError may escape: a non-array section carries no colours
        // and the documented defaults answer (Chroma loader semantics).
        $theme = VsCodeThemeLoader::fromArray($shape);

        $this->assertSame('#0d1117', $theme->background);
        $this->assertSame('#ff5f56', $theme->windowRed, 'junk scopes must not repaint chrome');
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
        $fifo = sys_get_temp_dir() . '/cs27-vsc-' . bin2hex(random_bytes(6)) . '.fifo';
        if (!posix_mkfifo($fifo, 0600)) {
            $this->markTestSkipped('could not create FIFO (named pipes not permitted here)');
        }
        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessageMatches('/not a regular file/');
            VsCodeThemeLoader::load($fifo);
        } finally {
            unlink($fifo);
        }
    }
}

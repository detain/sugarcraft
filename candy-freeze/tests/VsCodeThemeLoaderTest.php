<?php

declare(strict_types=1);

namespace SugarCraft\Freeze\Tests;

use SugarCraft\Freeze\Theme\VsCodeThemeLoader;
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

        // lineNumber is mapped from comment.
        $this->assertSame('#6272a4', $theme->lineNumber);
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
        $this->assertSame('#f7768e', $theme->windowRed);
        $this->assertSame('#9ece6a', $theme->windowGreen);
        $this->assertSame('#ff9e64', $theme->windowYellow);
    }
}

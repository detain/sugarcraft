<?php

declare(strict_types=1);

namespace SugarCraft\Freeze\Tests;

use SugarCraft\Freeze\Theme\ChromaThemeLoader;
use PHPUnit\Framework\TestCase;

final class ChromaThemeLoaderTest extends TestCase
{
    public function testLoadThrowsOnMissingFile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not found/');
        ChromaThemeLoader::load('/nonexistent/theme.json');
    }

    public function testLoadThrowsOnInvalidJson(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'chroma_');
        file_put_contents($tmp, 'not json');
        try {
            $this->expectException(\JsonException::class);
            ChromaThemeLoader::load($tmp);
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

        $theme = ChromaThemeLoader::fromArray($data);

        $this->assertSame('#1e1e1e', $theme->background);
        $this->assertSame('#d4d4d4', $theme->foreground);
        $this->assertSame('#6a9955', $theme->lineNumber);
        $this->assertSame('#569cd6', $theme->windowRed);
    }

    public function testNormalizes3DigitHex(): void
    {
        $data = [
            'background' => '#111',
            'foreground' => '#aaa',
            'colors' => [],
        ];

        $theme = ChromaThemeLoader::fromArray($data);

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

        $theme = ChromaThemeLoader::fromArray($data);

        $this->assertSame('#1e1e1e', $theme->background);
        $this->assertSame('#d4d4d4', $theme->foreground);
    }

    public function testDefaultsWhenColorsMissing(): void
    {
        $data = [
            'colors' => [],
        ];

        $theme = ChromaThemeLoader::fromArray($data);

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

        $theme = ChromaThemeLoader::fromArray($data);

        $this->assertSame('#6272a4', $theme->lineNumber);
        $this->assertSame('#ff79c6', $theme->windowRed);
        $this->assertSame('#f1fa8c', $theme->windowGreen);
    }

    public function testLoadFromFileRoundTrip(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'chroma_');
        $json = json_encode([
            'background' => '#282a36',
            'foreground' => '#f8f8f2',
            'colors' => [],
        ], JSON_THROW_ON_ERROR);
        file_put_contents($tmp, $json);

        try {
            $theme = ChromaThemeLoader::load($tmp);
            $this->assertSame('#282a36', $theme->background);
            $this->assertSame('#f8f8f2', $theme->foreground);
        } finally {
            unlink($tmp);
        }
    }

    public function testFullChromaThemeWithAllColors(): void
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

        $theme = ChromaThemeLoader::fromArray($data);
        $this->assertSame('#1a1b26', $theme->background);
        $this->assertSame('#a9b1d6', $theme->foreground);
        $this->assertSame('#565f89', $theme->lineNumber);
        $this->assertSame('#f7768e', $theme->windowRed);
        $this->assertSame('#9ece6a', $theme->windowGreen);
        $this->assertSame('#ff9e64', $theme->windowYellow);
    }
}

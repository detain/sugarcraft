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
}

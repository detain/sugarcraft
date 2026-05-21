<?php

declare(strict_types=1);

namespace SugarCraft\Glow\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Glow\GlamourTheme;

/**
 * @covers \SugarCraft\Glow\GlamourTheme
 */
final class GlamourThemeTest extends TestCase
{
    public function testFromJsonParsesValidJson(): void
    {
        $json = json_encode([
            'block_prefix' => '>>>',
            'block_suffix' => '<<<',
            'indent_token' => '→',
            'margin' => 2,
            'chroma' => [
                'keyword' => '1;31',
                'string' => '32',
            ],
        ]);

        $theme = GlamourTheme::fromJson($json);

        self::assertSame('>>>', $theme->blockPrefix);
        self::assertSame('<<<', $theme->blockSuffix);
        self::assertSame('→', $theme->indentToken);
        self::assertSame(2, $theme->margin);
        self::assertSame('1;31', $theme->chroma['keyword']);
        self::assertSame('32', $theme->chroma['string']);
    }

    public function testFromJsonReturnsDefaultsForEmptyJson(): void
    {
        $theme = GlamourTheme::fromJson('{}');

        self::assertSame('', $theme->blockPrefix);
        self::assertSame('', $theme->blockSuffix);
        self::assertSame('    ', $theme->indentToken);
        self::assertSame(0, $theme->margin);
        self::assertSame([], $theme->chroma);
    }

    public function testFromJsonReturnsDefaultsForInvalidJson(): void
    {
        $theme = GlamourTheme::fromJson('not valid json at all');

        self::assertSame('', $theme->blockPrefix);
        self::assertSame('', $theme->blockSuffix);
        self::assertSame('    ', $theme->indentToken);
        self::assertSame(0, $theme->margin);
        self::assertSame([], $theme->chroma);
    }

    public function testFromJsonIgnoresNonStringChromaValues(): void
    {
        $json = json_encode([
            'chroma' => [
                'keyword' => '1;31',
                'number' => 123,
                'valid' => '36',
            ],
        ]);

        $theme = GlamourTheme::fromJson($json);

        self::assertSame('1;31', $theme->chroma['keyword']);
        self::assertNull($theme->chroma['number'] ?? null);
        self::assertSame('36', $theme->chroma['valid']);
    }

    public function testFromFileLoadsThemeFromPath(): void
    {
        $path = sys_get_temp_dir() . '/test_glamour_theme.json';
        file_put_contents($path, json_encode([
            'block_prefix' => '[[',
            'block_suffix' => ']]',
            'indent_token' => '|',
            'margin' => 3,
            'chroma' => ['comment' => '90'],
        ]));

        $theme = GlamourTheme::fromFile($path);

        self::assertSame('[[', $theme->blockPrefix);
        self::assertSame(']]', $theme->blockSuffix);
        self::assertSame('|', $theme->indentToken);
        self::assertSame(3, $theme->margin);
        self::assertSame('90', $theme->chroma['comment']);

        unlink($path);
    }

    public function testFromFileReturnsDefaultsForNonExistentPath(): void
    {
        $theme = GlamourTheme::fromFile('/non/existent/path.json');

        self::assertSame('', $theme->blockPrefix);
        self::assertSame('', $theme->blockSuffix);
        self::assertSame('    ', $theme->indentToken);
        self::assertSame(0, $theme->margin);
        self::assertSame([], $theme->chroma);
    }

    public function testResolveReturnsChromaColor(): void
    {
        $theme = GlamourTheme::fromJson(json_encode([
            'chroma' => [
                'keyword' => '1;31',
                'string' => '32',
            ],
        ]));

        self::assertSame('1;31', $theme->resolve('keyword'));
        self::assertSame('32', $theme->resolve('string'));
    }

    public function testResolveReturnsNullForUnknownToken(): void
    {
        $theme = new GlamourTheme();

        self::assertNull($theme->resolve('nonexistent'));
    }

    public function testResolveReturnsNullWithEmptyChroma(): void
    {
        $theme = new GlamourTheme(chroma: []);

        self::assertNull($theme->resolve('keyword'));
    }
}

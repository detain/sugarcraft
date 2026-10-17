<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use SugarCraft\Core\Util\Palettes;
use PHPUnit\Framework\TestCase;

final class PalettesTest extends TestCase
{
    public function testDraculaPinsCanonicalHexValues(): void
    {
        $this->assertSame('#ff79c6', Palettes::DRACULA['pink']);
        $this->assertSame('#bd93f9', Palettes::DRACULA['purple']);
        $this->assertSame('#282a36', Palettes::DRACULA['background']);
        $this->assertSame('#6272a4', Palettes::DRACULA['comment']);
        $this->assertSame('#44475a', Palettes::DRACULA['currentLine']);
        $this->assertSame('#50fa7b', Palettes::DRACULA['green']);
        $this->assertSame('#ffb86c', Palettes::DRACULA['orange']);
        $this->assertSame('#f1fa8c', Palettes::DRACULA['yellow']);
        $this->assertSame('#ff5555', Palettes::DRACULA['red']);
        $this->assertSame('#8be9fd', Palettes::DRACULA['cyan']);
        $this->assertSame('#f8f8f2', Palettes::DRACULA['foreground']);
        $this->assertSame('#383a46', Palettes::DRACULA['separator']);
    }

    public function testOneDarkPinsCanonicalHexValues(): void
    {
        $this->assertSame('#61afef', Palettes::ONE_DARK['blue']);
        $this->assertSame('#c678dd', Palettes::ONE_DARK['magenta']);
        $this->assertSame('#282c34', Palettes::ONE_DARK['background']);
    }

    public function testGithubDarkPinsCanonicalHexValues(): void
    {
        $this->assertSame('#58a6ff', Palettes::GITHUB_DARK['blue']);
        $this->assertSame('#f778ba', Palettes::GITHUB_DARK['pink']);
        $this->assertSame('#0d1117', Palettes::GITHUB_DARK['background']);
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function schemeProvider(): iterable
    {
        yield 'dracula'     => [Palettes::DRACULA];
        yield 'one_dark'    => [Palettes::ONE_DARK];
        yield 'github_dark' => [Palettes::GITHUB_DARK];
    }

    /**
     * @dataProvider schemeProvider
     *
     * @param array<string, string> $scheme
     */
    public function testEveryValueIsLowercaseSixDigitHex(array $scheme): void
    {
        $this->assertNotEmpty($scheme);
        foreach ($scheme as $name => $hex) {
            $this->assertMatchesRegularExpression(
                '/^#[0-9a-f]{6}$/',
                $hex,
                "colour '{$name}' must be a lowercase #rrggbb hex string",
            );
        }
    }

    public function testHexHelperReturnsValue(): void
    {
        $this->assertSame('#ff79c6', Palettes::hex('dracula', 'pink'));
        $this->assertSame('#c678dd', Palettes::hex('one_dark', 'magenta'));
        $this->assertSame('#58a6ff', Palettes::hex('GitHub_Dark', 'blue')); // case-insensitive scheme
    }

    public function testHexHelperThrowsOnUnknownScheme(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Palettes::hex('solarized', 'blue');
    }

    public function testHexHelperThrowsOnUnknownColour(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Palettes::hex('dracula', 'chartreuse');
    }
}

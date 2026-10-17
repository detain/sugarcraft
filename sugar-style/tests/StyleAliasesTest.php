<?php

declare(strict_types=1);

namespace SugarCraft\Style\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Style\Style;

/**
 * DX-sugar aliases on Style: fg/bg/on/of/pad/mg.
 *
 * They share render output with their long-form counterparts, so each
 * test renders both forms and asserts they're byte-identical.
 */
final class StyleAliasesTest extends TestCase
{
    public function testFgWithColorMatchesForeground(): void
    {
        $cyan = Color::ansi(14);
        $long  = Style::new()->foreground($cyan)->render('hi');
        $short = Style::new()->fg($cyan)->render('hi');
        $this->assertSame($long, $short);
    }

    public function testFgAcceptsHexString(): void
    {
        $long  = Style::new()->foreground(Color::hex('#ff5'))->render('hi');
        $short = Style::new()->fg('#ff5')->render('hi');
        $this->assertSame($long, $short);
    }

    public function testBgAcceptsHexString(): void
    {
        $long  = Style::new()->background(Color::hex('#1e3a8a'))->render('hi');
        $short = Style::new()->bg('#1e3a8a')->render('hi');
        $this->assertSame($long, $short);
    }

    public function testOnIsBackgroundAlias(): void
    {
        $long  = Style::new()->fg('#fff')->background(Color::hex('#001'))->render('hi');
        $short = Style::new()->fg('#fff')->on('#001')->render('hi');
        $this->assertSame($long, $short);
    }

    public function testFgNullClearsForeground(): void
    {
        $set = Style::new()->fg('#abc');
        $this->assertNotSame(null, $set->getForeground());
        $this->assertNull($set->fg(null)->getForeground());
    }

    public function testOfBindsContent(): void
    {
        $bound = Style::new()->of('greetings');
        $this->assertSame('greetings', $bound->value());
        $this->assertSame('greetings', $bound->render());
    }

    public function testPadOneArgAppliesToAllSides(): void
    {
        $long  = Style::new()->padding(2)->render('x');
        $short = Style::new()->pad(2)->render('x');
        $this->assertSame($long, $short);
    }

    public function testPadFourArgsTopRightBottomLeft(): void
    {
        $long  = Style::new()->padding(1, 2, 3, 4)->render('x');
        $short = Style::new()->pad(1, 2, 3, 4)->render('x');
        $this->assertSame($long, $short);
    }

    public function testMgOneArgAppliesToAllSides(): void
    {
        $long  = Style::new()->margin(2)->render('x');
        $short = Style::new()->mg(2)->render('x');
        $this->assertSame($long, $short);
    }

    public function testFullChainShortFormMatchesLongForm(): void
    {
        $long = Style::new()
            ->foreground(Color::hex('#fff'))
            ->background(Color::hex('#1e3a8a'))
            ->padding(1, 2)
            ->margin(0, 1)
            ->bold()
            ->setString('Sugar')
            ->render();

        $short = Style::new()
            ->fg('#fff')
            ->on('#1e3a8a')
            ->pad(1, 2)
            ->mg(0, 1)
            ->bold()
            ->of('Sugar')
            ->render();

        $this->assertSame($long, $short);
    }
}

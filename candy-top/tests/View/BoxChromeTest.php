<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\View;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Top\Theme\TtyTheme;
use SugarCraft\Top\View\BoxChrome;
use SugarCraft\Top\View\Ink;
use SugarCraft\Top\View\Rect;
use SugarCraft\Top\View\Surface;

final class BoxChromeTest extends TestCase
{
    private Ink $ink;

    protected function setUp(): void
    {
        $this->ink = Ink::new(TtyTheme::new(), ColorProfile::Ansi);
    }

    public function testCreateBoxCellGrid(): void
    {
        $s = Surface::new(14, 4);
        BoxChrome::paint($s, Rect::new(0, 0, 14, 4), $this->ink->fg('cpu_box'), $this->ink, Border::rounded(), true, 'cpu', 'up', 1);

        $this->assertSame([
            '╭─┐¹cpu┌─────╮',
            '│            │',
            '│            │',
            '╰─┘¹up└──────╯',
        ], $s->plainLines());
    }

    public function testSquareCornersAndTtyNumbering(): void
    {
        $s = Surface::new(10, 3);
        BoxChrome::paint($s, Rect::new(0, 0, 10, 3), '', $this->ink, Border::normal(), false, 'mem', num: 2, tty: true);

        $this->assertSame(['┌─┐2mem┌─┐', '│        │', '└────────┘'], $s->plainLines());
    }

    public function testTitleSgrSnapshot(): void
    {
        $s = Surface::new(9, 2);
        BoxChrome::paint($s, Rect::new(0, 0, 9, 2), "\x1b[32m", $this->ink, Border::rounded(), false, 'net', num: 3);

        // line colour, then bold + hi_fg superscript, title colour text, line colour junction.
        $this->assertSame(
            "\x1b[0m\x1b[32m╭─┐\x1b[0m\x1b[1;91m³\x1b[0m\x1b[1;97mnet\x1b[0m\x1b[32m┌╮\x1b[0m",
            $s->lines()[0],
        );
    }

    public function testEmbedIsClippedInsideTheCorners(): void
    {
        $s = Surface::new(8, 2);
        $box = Rect::new(0, 0, 8, 2);
        BoxChrome::paint($s, $box, '', $this->ink, Border::rounded());
        $written = BoxChrome::embed($s, 4, 0, 'toolong', '', Border::rounded(), false, $box);

        $this->assertSame('╭───┐to╮', $s->plainLines()[0]);
        $this->assertSame(3, $written);
    }

    public function testBottomEmbedUsesDownJunctions(): void
    {
        $s = Surface::new(10, 2);
        $box = Rect::new(0, 0, 10, 2);
        BoxChrome::paint($s, $box, '', $this->ink, Border::rounded());
        BoxChrome::embed($s, 3, 1, 'ab', '', Border::rounded(), true, $box);

        $this->assertSame('╰──┘ab└──╯', $s->plainLines()[1]);
    }

    public function testDegenerateRectDrawsNothing(): void
    {
        $s = Surface::new(3, 3);
        BoxChrome::paint($s, Rect::new(0, 0, 1, 3), '', $this->ink, Border::rounded(), true, 'x');
        $this->assertSame(['   ', '   ', '   '], $s->plainLines());
    }
}

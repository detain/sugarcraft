<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\View;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;
use SugarCraft\Top\View\Rect;
use SugarCraft\Top\View\SizeError;
use SugarCraft\Top\View\Symbols;
use SugarCraft\Top\View\Units;

final class MiscViewTest extends TestCase
{
    protected function setUp(): void
    {
        T::reset();
    }

    public function testSizeErrorLayoutMatchesBtop(): void
    {
        $s = SizeError::surface(50, 20, 80, 24);
        $rows = $s->plainLines();
        $this->assertCount(20, $rows);
        // btop rows h/2-2, h/2-1, h/2+1, h/2+2 (1-based) -> 7, 8, 10, 11.
        $this->assertSame('             Terminal size too small:', rtrim($rows[7]));
        $this->assertSame('               Width = 50 Height = 20', rtrim($rows[8]));
        $this->assertSame('            Needed for current config:', rtrim($rows[10]));
        $this->assertSame('              Width = 80 Height = 24', rtrim($rows[11]));
        foreach ($s->lines() as $line) {
            $this->assertSame(50, Width::string($line));
        }
    }

    public function testSizeErrorColoursTheShortDimension(): void
    {
        $s = SizeError::surface(90, 20, 80, 24);
        // width 90 >= 80 is green, height 20 < 24 is red.
        $row = $s->lines()[8];
        $this->assertStringContainsString(SizeError::GREEN . '9', $row);
        $this->assertStringContainsString(SizeError::RED . '2', $row);
    }

    public function testSizeErrorOnATinyTerminalStillFillsIt(): void
    {
        $lines = SizeError::surface(10, 3, 80, 24)->lines();
        $this->assertCount(3, $lines);
        foreach ($lines as $line) {
            $this->assertSame(10, Width::string($line));
        }
    }

    /**
     * A terminal that declares it cannot carry colour (NO_COLOR resolves to
     * Ascii, TERM=dumb to NoTty) gets the notice without btop's red/green
     * verdict — the decoration is the only thing it cannot render, and the
     * message is the numbers and the geometry, which survive untouched.
     */
    public function testTheSizeNoticeDropsTheColourVerdictOnAColourlessProfile(): void
    {
        $coloured = SizeError::surface(90, 20, 80, 24);

        foreach ([ColorProfile::Ascii, ColorProfile::NoTty] as $profile) {
            $mono = SizeError::surface(90, 20, 80, 24, $profile);
            $this->assertSame($coloured->plainLines(), $mono->plainLines(), $profile->name . ' keeps btop geometry');
            $this->assertStringContainsString('Width = 90 Height = 20', $mono->plainLines()[8]);

            foreach ($mono->lines() as $line) {
                $this->assertStringNotContainsString(SizeError::WHITE, $line, $profile->name . ' emits no white verdict');
                $this->assertStringNotContainsString(SizeError::GREEN, $line, $profile->name . ' emits no green verdict');
                $this->assertStringNotContainsString(SizeError::RED, $line, $profile->name . ' emits no red verdict');
            }
        }

        // The default — every caller with no ink to consult — stays btop's bytes.
        $this->assertStringContainsString(SizeError::GREEN . '9', $coloured->lines()[8]);
        $this->assertStringContainsString(SizeError::RED . '2', $coloured->lines()[8]);
        $this->assertSame(SizeError::render(90, 20, 80, 24), $coloured->render());
    }

    /**
     * #101: an accidental shrink to a size the notice itself cannot occupy
     * used to paint clipped fragments (" erm", "= 120 H") — meaningless.
     * Below its own width the notice compacts to the actionable core: each
     * dimension's label letter, its current value in the verdict colour, and
     * the minimum it must reach.
     */
    public function testTheSizeNoticeCompactsToAnActionableHintOnATinyTerminal(): void
    {
        $rows = SizeError::surface(5, 10, 80, 24)->plainLines();
        $this->assertStringContainsString('W 5', $rows[3]);
        $this->assertStringContainsString('>=80', $rows[4]);
        $this->assertStringContainsString('H 10', $rows[5]);
        $this->assertStringContainsString('>=24', $rows[6]);
        $this->assertStringContainsString(SizeError::RED . '5', SizeError::surface(5, 10, 80, 24)->lines()[3]);

        // 8x20 fits neither paired line ("H 20 >=24" is nine cells), so the
        // four parts stack one per row.
        $rows = SizeError::surface(8, 20, 80, 24)->plainLines();
        $this->assertStringContainsString('W 8', $rows[8]);
        $this->assertStringContainsString('>=80', $rows[9]);
        $this->assertStringContainsString('H 20', $rows[10]);
        $this->assertStringContainsString('>=24', $rows[11]);

        // Nine columns carry each dimension as one paired line.
        $rows = SizeError::surface(9, 20, 80, 24)->plainLines();
        $this->assertStringContainsString('W 9 >=80', $rows[9]);
        $this->assertStringContainsString('H 20 >=24', $rows[10]);

        // The compact form is colour-free on a colourless profile, numbers intact.
        $mono = SizeError::surface(5, 10, 80, 24, ColorProfile::Ascii);
        $this->assertSame('>=80 ', $mono->plainLines()[4]);
        foreach ($mono->lines() as $line) {
            $this->assertStringNotContainsString(SizeError::WHITE, $line);
            $this->assertStringNotContainsString(SizeError::RED, $line);
            $this->assertStringNotContainsString(SizeError::GREEN, $line);
        }
    }

    public function testUnits(): void
    {
        $this->assertSame('512 Byte', Units::bytes(512));
        $this->assertSame('1.50 KiB', Units::bytes(1536));
        $this->assertSame('16.0 GiB', Units::bytes(16 * 1024 ** 3));
        $this->assertSame('300 MiB/s', Units::bytes(300 * 1024 ** 2, true));
        $this->assertSame('0 Byte', Units::bytes(-5));
    }

    public function testSymbolsNumber(): void
    {
        $this->assertSame('¹', Symbols::number(1, false));
        $this->assertSame('1', Symbols::number(1, true));
        $this->assertSame('⁹', Symbols::number(42, false));
    }

    public function testRectGeometry(): void
    {
        $r = Rect::new(2, 3, 4, 5);
        $this->assertSame(6, $r->right());
        $this->assertSame(8, $r->bottom());
        $this->assertTrue($r->contains(5, 7));
        $this->assertFalse($r->contains(6, 7));
        $this->assertTrue($r->intersect(Rect::new(10, 10, 2, 2))->isEmpty());
        $this->assertTrue(Rect::new(3, 4, 3, 4)->equals($r->intersect(Rect::new(3, 4, 10, 10))));
        $this->assertSame(0, Rect::new(0, 0, -3, 1)->width);
    }


    /** The gpu selector arrows are ASCII under force_tty, like every other decoration. */
    public function testTheSelectorArrowsFollowTtyMode(): void
    {
        $this->assertSame(Symbols::LEFT, Symbols::arrow(right: false, tty: false));
        $this->assertSame(Symbols::RIGHT, Symbols::arrow(right: true, tty: false));
        $this->assertSame(Symbols::LEFT_TTY, Symbols::arrow(right: false, tty: true));
        $this->assertSame(Symbols::RIGHT_TTY, Symbols::arrow(right: true, tty: true));
        foreach ([true, false] as $tty) {
            $this->assertSame(1, Width::string(Symbols::arrow(right: true, tty: $tty)), 'an arrow is always one column');
        }
    }
}
<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Plot\ProcRow;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Dash\Plot\ProcRow\ProcRow;
use SugarCraft\Dash\Plot\ProcRow\ProcRowPalette;

final class ProcRowPaletteTest extends TestCase
{
    private static function rgb(Color $c): array
    {
        return [$c->r, $c->g, $c->b];
    }

    public function testNewDerivesBtopInjectedRamps(): void
    {
        $p = ProcRowPalette::new(
            Color::rgb(200, 200, 200),
            Color::rgb(0, 0, 0),
            [Color::rgb(100, 0, 0), Color::rgb(0, 0, 100)],
            Color::rgb(1, 2, 3),
            Color::rgb(4, 5, 6),
        );
        // proc = main_fg → inactive_fg
        $this->assertSame([200, 200, 200], self::rgb($p->proc[0]));
        $this->assertSame([100, 100, 100], self::rgb($p->proc[50]));
        // proc_color = inactive_fg → process_start
        $this->assertSame([0, 0, 0], self::rgb($p->procColor[0]));
        $this->assertSame([50, 0, 0], self::rgb($p->procColor[50]));
        $this->assertSame([100, 0, 0], self::rgb($p->procColor[100]));
        // process = its own stops
        $this->assertSame([50, 0, 50], self::rgb($p->process[50]));
        // followed defaults to the selected pair
        $this->assertSame([1, 2, 3], self::rgb($p->followedBg));
        $this->assertSame([4, 5, 6], self::rgb($p->followedFg));
    }

    public function testBtopDefaultTheme(): void
    {
        $p = ProcRowPalette::btop();
        $this->assertSame([204, 204, 204], self::rgb($p->mainFg));
        $this->assertSame([64, 64, 64], self::rgb($p->inactiveFg));
        $this->assertSame([106, 47, 47], self::rgb($p->selectedBg));
        $this->assertSame([238, 238, 238], self::rgb($p->selectedFg));
        $this->assertSame([64, 64, 181], self::rgb($p->followedBg));
        // three-stop process ramp meets mid exactly at 50, ends at end
        $this->assertSame([220, 209, 121], self::rgb($p->process[50]));
        $this->assertSame([212, 84, 84], self::rgb($p->process[100]));
    }

    public function testWithersReturnNewInstances(): void
    {
        $a = ProcRowPalette::btop();
        $b = $a->withSelected(Color::rgb(9, 9, 9), Color::rgb(8, 8, 8))->withFollowed(Color::rgb(7, 7, 7), Color::rgb(6, 6, 6));
        $this->assertSame([106, 47, 47], self::rgb($a->selectedBg));
        $this->assertSame([9, 9, 9], self::rgb($b->selectedBg));
        $this->assertSame([8, 8, 8], self::rgb($b->selectedFg));
        $this->assertSame([7, 7, 7], self::rgb($b->followedBg));
        $this->assertSame([6, 6, 6], self::rgb($b->followedFg));
        $this->assertSame($a->proc, $b->proc);
    }

    public function testWithRampsValidates(): void
    {
        $ramp = array_fill(0, 101, Color::rgb(1, 1, 1));
        $p = ProcRowPalette::btop()->withRamps($ramp, $ramp, $ramp);
        $this->assertSame($ramp, $p->process);
        $this->expectException(\InvalidArgumentException::class);
        ProcRowPalette::btop()->withRamps($ramp, array_slice($ramp, 1), $ramp);
    }

    public function testProcRowDto(): void
    {
        $r = ProcRow::new(5, 'n', 'c', 3, 'u', 1.5, 2.5, '1M');
        $this->assertSame([5, 'n', 'c', 3, 'u', 1.5, 2.5, '1M'], [$r->pid, $r->name, $r->cmd, $r->threads, $r->user, $r->cpu, $r->memPercent, $r->memLabel]);
        $this->expectException(\InvalidArgumentException::class);
        ProcRow::new(1, 'x', cpu: INF);
    }
}

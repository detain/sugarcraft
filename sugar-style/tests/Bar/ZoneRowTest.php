<?php

declare(strict_types=1);

namespace SugarCraft\Style\Tests\Bar;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Style\Bar\ZoneEntry;
use SugarCraft\Style\Bar\ZonePiece;
use SugarCraft\Style\Bar\ZoneRow;

final class ZoneRowTest extends TestCase
{
    private const OPEN = "\u{E000}";
    private const CLOSE = "\u{E001}";

    /** @return list<ZoneEntry> */
    private function tabs(int $n): array
    {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = ZoneEntry::of([
                ZonePiece::of("t$i", "tab:$i", static fn (string $p): string => "\e[1m{$p}\e[0m"),
                ZonePiece::of(' ×', "x:$i"),
            ], $i);
        }
        return $out;
    }

    private function row(int $n): ZoneRow
    {
        return ZoneRow::new()
            ->withLead(ZonePiece::of('[+]', 'new'))
            ->withEntries($this->tabs($n))
            ->withOverflow(static fn (int $c): ZoneEntry => ZoneEntry::of([ZonePiece::of("‹{$c}›")]))
            ->withMarker(static fn (string $id, string $s): string => self::OPEN . $s . self::CLOSE)
            ->withZoneGate(static fn (string $id): bool => $id !== 'tab:2');
    }

    public function testEverythingFitsNoOverflow(): void
    {
        $row = $this->row(2);
        $this->assertSame("[+] \e[1mt1\e[0m × \e[1mt2\e[0m ×", $row->render(40, false));
        $this->assertSame(13, Width::string($row->render(40, false)));
        $plan = $row->plan(40);
        $this->assertCount(2, $plan->placed());
        $this->assertNull($plan->overflow());
    }

    public function testWidthIsPlainSum(): void
    {
        $this->assertSame(3 + 2 * (4 + 1), $this->row(2)->width());
    }

    public function testOverflowCollapsesTailWithTrueCount(): void
    {
        $row = $this->row(5);
        // lead 3 + 2 tabs (5 each) = 13, marker " ‹3›" = 4 -> 17
        $this->assertSame("[+] \e[1mt1\e[0m × \e[1mt2\e[0m × ‹3›", $row->render(17, false));
        $plan = $row->plan(17);
        $this->assertCount(2, $plan->placed());
        $this->assertCount(3, $plan->dropped());
        $this->assertSame([3, 4, 5], array_map(static fn (ZoneEntry $e) => $e->tag(), $plan->dropped()));
    }

    public function testTrimsTrailingWhenTrueCountMarkerWidensRow(): void
    {
        // 12 tabs: marker widens from ‹N› to ‹NN› as the dropped count crosses 10.
        $row = ZoneRow::new()
            ->withLead(ZonePiece::of('+'))
            ->withEntries(array_map(static fn (int $i): ZoneEntry => ZoneEntry::of([ZonePiece::of('a')], $i), range(1, 12)))
            ->withOverflow(static fn (int $c): ZoneEntry => ZoneEntry::of([ZonePiece::of("‹{$c}›")]));
        foreach (range(1, 40) as $cols) {
            $out = $row->render($cols, false);
            $this->assertLessThanOrEqual($cols, Width::string($out), "cols=$cols");
        }
    }

    public function testBudgetBelowLeadIsEmpty(): void
    {
        $this->assertSame('', $this->row(3)->render(2, false));
        $this->assertSame([], $this->row(3)->zones(2));
        $this->assertNull($this->row(3)->plan(2));
    }

    public function testLeadAloneWhenNothingFitsAndMarkerNeverFits(): void
    {
        $this->assertSame('[+]', $this->row(3)->render(3, false));
        $this->assertSame('[+]', $this->row(3)->render(6, false));
        $this->assertSame(self::OPEN . '[+]' . self::CLOSE, $this->row(3)->render(3, true));
    }

    public function testMarkedRenderStripsToPaintedAndZonesAlign(): void
    {
        $row = $this->row(3);
        foreach ([13, 17, 40] as $cols) {
            $plain = $row->render($cols, false);
            $marked = $row->render($cols, true);
            $this->assertSame($plain, str_replace([self::OPEN, self::CLOSE], '', $marked));
        }
    }

    public function testZonesSpansAndGate(): void
    {
        $zones = $this->row(3)->zones(40);
        // spans follow PLAIN widths; SGR in the painted text is zero-width.
        $this->assertSame([0, 0, 3, 'new'], $zones[0]);
        $this->assertSame([0, 4, 6, 'tab:1'], $zones[1]);
        $this->assertSame([0, 6, 8, 'x:1'], $zones[2]);
        // tab:2 refused by the gate: no span, but its close still has one.
        $ids = array_column($zones, 3);
        $this->assertNotContains('tab:2', $ids);
        $this->assertContains('x:2', $ids);
    }

    public function testGateRefusedPieceIsPaintedUnmarked(): void
    {
        $marked = $this->row(2)->render(40, true);
        $this->assertStringNotContainsString(self::OPEN . "\e[1mt2", $marked);
        $this->assertStringContainsString(self::OPEN . "\e[1mt1\e[0m" . self::CLOSE, $marked);
    }

    public function testSeparatorAfterShiftsPlacementNotCost(): void
    {
        $entries = array_map(static fn (string $t): ZoneEntry => ZoneEntry::of([ZonePiece::of($t, $t)]), ['ab', 'cd']);
        $before = ZoneRow::new()->withLead(ZonePiece::of(' '))->withEntries($entries)->withSeparator('   ');
        $after = $before->withSeparatorAfter();
        $this->assertSame($before->width(), $after->width());
        $this->assertSame(' ab   cd   ', $after->render(null, false));
        $this->assertSame('    ab   cd', $before->render(null, false));
        $this->assertSame([0, 1, 3, 'ab'], $after->zones(null)[0]);
        $this->assertSame([0, 6, 8, 'cd'], $after->zones(null)[1]);
    }

    public function testNullColsIsUnbounded(): void
    {
        $plan = $this->row(50)->plan(null);
        $this->assertCount(50, $plan->placed());
        $this->assertNull($plan->overflow());
    }

    public function testNoOverflowCallbackDropsSilently(): void
    {
        $row = ZoneRow::new()->withEntries($this->tabs(5));
        $plan = $row->plan(12);
        $this->assertCount(2, $plan->placed());
        $this->assertNull($plan->overflow());
    }

    public function testWithersReturnNewInstance(): void
    {
        $a = ZoneRow::new();
        $this->assertNotSame($a, $a->withSeparator('-'));
        $this->assertSame(0, $a->width());
    }

    public function testPieceAndEntryAccessors(): void
    {
        $p = ZonePiece::of('え', 'id', static fn (string $s): string => "<$s>");
        $this->assertSame('え', $p->plain());
        $this->assertSame('id', $p->id());
        $this->assertSame(2, $p->width());
        $this->assertSame('<え>', $p->painted());
        $e = ZoneEntry::of([$p, ZonePiece::of('x')], 'tag');
        $this->assertSame(3, $e->width());
        $this->assertCount(2, $e->pieces());
        $this->assertSame('tag', $e->tag());
    }
}

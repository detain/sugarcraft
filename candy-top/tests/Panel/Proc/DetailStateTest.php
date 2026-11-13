<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Panel\Proc;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\ProcDetail;
use SugarCraft\Top\Collect\Process;
use SugarCraft\Top\Panel\Proc\DetailState;
use SugarCraft\Top\Panel\Proc\ProcEntry;

/**
 * Direct unit coverage for DetailState::observe()'s core math
 * (candy-top-103): the mem-graph ceiling re-seed band, the per-core cpu
 * fold with its 0..100 clamp, the 512 trim, parent one-time retention and
 * extra keep-on-failure — none of it was asserted anywhere before.
 */
final class DetailStateTest extends TestCase
{
    private static function entry(float $cpu, int $mem, string $state = 'S'): ProcEntry
    {
        $process = new Process(7, 1, 'svc', '/usr/bin/svc', 'joe', 1000, $state, 1, 0, $mem, $cpu, $cpu);

        return new ProcEntry($process, $cpu, $cpu, $mem, 1, 0.0, 0.0);
    }

    public function testOpenSeedsFromTheGivenEntry(): void
    {
        $d = DetailState::open(7, self::entry(1.0, 100));

        $this->assertSame(7, $d->pid);
        $this->assertTrue($d->alive);
        $this->assertSame(-1, $d->firstMem);
        $this->assertSame([], $d->cpu);
        $this->assertSame([], $d->mem);

        $dead = DetailState::open(7, null);
        $this->assertFalse($dead->alive);
        $this->assertSame('dead', $dead->status());
    }

    public function testCpuFoldMultipliesByCoresAndClampsToHundred(): void
    {
        $d = DetailState::open(7, self::entry(1.0, 100))
            ->observe(self::entry(1.5, 100), null, 4, false, 1000, '')  // 1.5 × 4 = 6
            ->observe(self::entry(40.0, 100), null, 4, false, 1000, '') // 160 → clamp 100
            ->observe(self::entry(0.0, 100), null, 4, false, 1000, '');

        $this->assertSame([6, 100, 0], $d->cpu);
    }

    public function testCpuFoldWithPerCoreSkipsTheMultiplier(): void
    {
        $d = DetailState::open(7, self::entry(0.0, 100))
            ->observe(self::entry(1.5, 100), null, 4, true, 1000, '');

        $this->assertSame([2], $d->cpu, 'round(1.5) per cent of total, not × cores');
    }

    public function testMemCeilingSeedsOnceAndReseedsOnlyOutsideTheBand(): void
    {
        $d = DetailState::open(7, null);

        // -1 seed: first sample plants 2 × mem (memTotal guard: 0 total → still now*2).
        $d = $d->observe(self::entry(0.0, 500), null, 1, false, 0, '');
        $this->assertSame(1000, $d->firstMem);

        // Inside the band [first/4, first×2] … nothing reseeds: 250 ≤ mem ≤ 2000.
        $d = $d->observe(self::entry(0.0, 250), null, 1, false, 8192, '');
        $this->assertSame(1000, $d->firstMem);
        $d = $d->observe(self::entry(0.0, 2000), null, 1, false, 8192, '');
        $this->assertSame(1000, $d->firstMem, 'the upper edge (first < now/2 fails at exactly 2×)');

        // Below the band (mem < first/4): reseed.
        $d = $d->observe(self::entry(0.0, 100), null, 1, false, 8192, '');
        $this->assertSame(200, $d->firstMem);

        // Above the band (first < mem/2): reseed, capped at memTotal.
        $d = $d->observe(self::entry(0.0, 9000), null, 1, false, 8192, '');
        $this->assertSame(8192, $d->firstMem, 'min(2 × mem, memTotal)');
    }

    public function testBothDequesTrimToTheHistoryWindow(): void
    {
        $d = DetailState::open(7, self::entry(0.0, 1));
        for ($i = 1; $i <= 520; $i++) {
            $d = $d->observe(self::entry(0.0, 60), null, 1, false, 0, '');
        }

        $this->assertCount(DetailState::HISTORY, $d->cpu);
        $this->assertCount(DetailState::HISTORY, $d->mem);
        $this->assertSame(60, $d->mem[DetailState::HISTORY - 1], 'newest last');
    }

    public function testParentIsRetainedOnceAndExtraKeepsTheLastNonNull(): void
    {
        $extra = new ProcDetail(7, '/srv');
        $d = DetailState::open(7, self::entry(0.0, 10))
            ->observe(self::entry(0.0, 10), $extra, 1, false, 1000, 'sh');

        $this->assertSame('sh', $d->parent);
        $this->assertSame($extra, $d->extra);

        $d = $d->observe(self::entry(0.0, 10), null, 1, false, 1000, '');
        $this->assertSame('sh', $d->parent, 'a later empty parent does not erase it');
        $this->assertSame($extra, $d->extra, 'a failed extra read keeps the previous one');
    }

    public function testDeathKeepsTheLastEntryHistoriesAndReportsDead(): void
    {
        $d = DetailState::open(7, self::entry(1.0, 10))
            ->observe(self::entry(2.0, 20), null, 1, false, 1000, 'p');

        $gone = $d->observe(null, null, 1, false, 1000, '');

        $this->assertFalse($gone->alive);
        $this->assertSame($d->entry, $gone->entry, 'the dead process stays visible, btop-greyed');
        $this->assertSame($d->cpu, $gone->cpu, 'histories are not touched by the death fold');
        $this->assertSame('dead', $gone->status());
    }

    public function testStatusSpellings(): void
    {
        $state = static fn (string $s): string => DetailState::open(7, self::entry(0.0, 1, $s))->status();

        $this->assertSame('running', $state('R'));
        $this->assertSame('sleeping', $state('S'));
        $this->assertSame('waiting', $state('D'));
        $this->assertSame('zombie', $state('Z'));
        $this->assertSame('stopped', $state('T'));
        $this->assertSame('tracing', $state('t'));
        $this->assertSame('dead', $state('X'));
        $this->assertSame('wakekill', $state('K'));
        $this->assertSame('parked', $state('P'));
        $this->assertSame('unknown', $state('?'));
    }
}

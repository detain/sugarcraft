<?php

declare(strict_types=1);

namespace SugarCraft\Vterm\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vterm\Terminal\Terminal;

/**
 * vterm-32: behavioural + coercion pins for the emulator façade's
 * `withTabStops(array $cols)` (src/Terminal/Terminal.php:519) — the public
 * guard/coercion is: int cast, negatives dropped, duplicates collapse into
 * the keyed table, values at/past the width are stored but unreachable
 * (forward()/backward() scan strictly inside the grid), and an empty array
 * clears every stop. HT/CHT/CBT/HTS/TBC all honour the installed table;
 * the façade method is immutable (the live terminal keeps its stops).
 */
final class TabStopsTest extends TestCase
{
    private const COLS = 40;
    private const ROWS = 5;

    private function terminal(): Terminal
    {
        return Terminal::new(self::COLS, self::ROWS);
    }

    private function col(Terminal $t): int
    {
        return $t->cursor()->col;
    }

    public function testCustomStopsDriveHtChtAndCbt(): void
    {
        $t = $this->terminal()->withTabStops([5, 15, 25]);

        $t->feed('a'); // col 1
        $t->feed("\t");
        $this->assertSame(5, $this->col($t), 'HT lands on the custom stop at 5');

        $t->feed('b'); // col 6
        $t->feed("\t");
        $this->assertSame(15, $this->col($t), 'HT lands on the custom stop at 15');

        $t->feed("\x1b[2I");
        $this->assertSame(39, $this->col($t), 'CSI 2I from 15 walks 25 then hits no stop and clamps to COLS-1');

        $t->feed("\x1b[2Z");
        $this->assertSame(15, $this->col($t), 'CSI 2Z from the right edge walks 25 then 15');
    }

    public function testCbtFromMidFieldWalksInstalledStops(): void
    {
        $t = $this->terminal()->withTabStops([5, 15, 25]);
        $t->feed("\x1b[1;26H"); // column 25
        $t->feed("\x1b[2Z");
        $this->assertSame(5, $this->col($t), 'CBT from 25 walks 15 then 5');
    }

    public function testHtsAddsToTheInstalledTable(): void
    {
        $t = $this->terminal()->withTabStops([5, 15, 25]);
        $t->feed("\x1b[1;4H"); // column 3
        $t->feed("\x1bH");     // HTS at 3
        $t->feed("\x1b[1;1H"); // home
        $t->feed("\t");
        $this->assertSame(3, $this->col($t), 'the hard stop at 3 precedes the custom 5');
    }

    public function testTbcClearsInstalledStops(): void
    {
        $t = $this->terminal()->withTabStops([5, 15, 25]);
        $t->feed("\x1b[1;6H"); // column 5 — ON the stop
        $t->feed("\x1b[g");    // TBC 0: clear the stop at the cursor
        $t->feed("\x1b[1;1H");
        $t->feed("\t");
        $this->assertSame(15, $this->col($t), 'stop 5 cleared, next is 15');

        $t->feed("\x1b[3g"); // TBC 3: everything
        $t->feed("\x1b[1;1H");
        $t->feed("\t");
        $this->assertSame(39, $this->col($t), 'with no stops left HT clamps to the right edge');
    }

    public function testEmptyArrayClearsEveryStop(): void
    {
        $t = $this->terminal()->withTabStops([]);
        $t->feed('a');
        $t->feed("\t");
        $this->assertSame(39, $this->col($t));
    }

    public function testUnsortedInputBehavesLikeSorted(): void
    {
        foreach ([
            $this->terminal()->withTabStops([25, 5, 15]),
            $this->terminal()->withTabStops([5, 15, 25]),
        ] as $t) {
            $t->feed('a');
            $t->feed("\t");
            $this->assertSame(5, $this->col($t), 'the keyed table is order-independent');
        }
    }

    public function testOutOfRangeHighValueIsStoredButUnreachable(): void
    {
        // The coercion keeps $i >= 0 (no upper gate); forward() scans only
        // strictly inside the grid, so a stop at/beyond the width never
        // captures the cursor — the documented "normalised" outcome.
        $t = $this->terminal()->withTabStops([100]);
        $t->feed('a');
        $t->feed("\t");
        $this->assertSame(39, $this->col($t), 'unreachable stop: HT falls through to the right edge');
    }

    public function testLowCustomStopIsAccepted(): void
    {
        // The accept-set floor is column 0: withTabStops([2, 10]) must keep
        // the stop at 2 (a guard tightened past the documented >= 0 floor
        // drops it and HT falls through to 10).
        $t = $this->terminal()->withTabStops([2, 10]);
        $t->feed('a');
        $t->feed("\t");
        $this->assertSame(2, $this->col($t));
    }

    public function testNegativeEntriesAreDroppedAndDuplicatesCollapse(): void
    {
        $t = $this->terminal()->withTabStops([-4, 5, 5, 15]);
        $t->feed('a');
        $t->feed("\t");
        $this->assertSame(5, $this->col($t), 'duplicated 5 is one keyed stop; -4 vanished');
    }

    public function testWithTabStopsIsImmutableOnTheLiveTerminal(): void
    {
        $t = $this->terminal();
        $changed = $t->withTabStops([5]);
        $t->feed('a');
        $t->feed("\t");
        $this->assertSame(8, $this->col($t), 'the receiver keeps the every-8 defaults');
        $changed->feed('a');
        $changed->feed("\t");
        $this->assertSame(5, $this->col($changed));
    }
}

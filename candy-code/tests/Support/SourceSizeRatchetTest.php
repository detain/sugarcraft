<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * The two god-files of this lib get a ceiling, not a lecture.
 *
 * The 2026-11 docs review (finding M6) counted src/Chat.php past 22,000 lines
 * and src/Cli/Bootstrap.php past 12,000 and asked for the growth to become
 * loud. Extraction is the ruling path (several traits already left these
 * files that way); this guard makes a silent regrowth impossible: each file
 * may never exceed its ratchet line, and the moment a change would cross it,
 * the right answer is another extraction — after which the author lowers the
 * ratchet to the new smaller measurement, because this ceiling only ever
 * ratchets DOWNWARD (the test refuses a ceiling above the live count).
 */
final class SourceSizeRatchetTest extends TestCase
{
    /**
     * Files under the ratchet with the ceiling each may not exceed, as frozen
     * at the 2026-11 review. Lower these numbers by extracting; never raise
     * them above the live line count.
     */
    private const CEILINGS = [
        'src/Chat.php' => 22_250,
        'src/Cli/Bootstrap.php' => 12_060,
    ];

    public function testNoRatchetedFileGrowsPastItsCeiling(): void
    {
        $root = \dirname(__DIR__, 2);
        $report = [];

        foreach (self::CEILINGS as $relative => $ceiling) {
            $path = $root . '/' . $relative;
            $this->assertFileExists($path, $relative . ' is a shipped file; if it moved, this roster moves with it');

            $live = \count(\file($path));
            if ($live > $ceiling) {
                $report[] = $relative . ': ' . $live . ' lines, ceiling ' . $ceiling
                    . ' — extract (the M6 ruling path) and then lower the ceiling';
            }
        }

        $this->assertSame([], $report, "File-size ratchet breached:\n" . \implode("\n", $report));
    }

    public function testTheCeilingsSitOnTheLiveCountsNotAboveThem(): void
    {
        $root = \dirname(__DIR__, 2);
        $slack = [];

        foreach (self::CEILINGS as $relative => $ceiling) {
            $live = \count(\file($root . '/' . $relative));
            // A ceiling more than 250 lines over the live count is stale
            // generosity: it lets that much silent regrowth back in.
            if ($ceiling - $live > 250) {
                $slack[] = $relative . ': ceiling ' . $ceiling . ' vs live ' . $live
                    . ' — tighten the ceiling to the new measurement';
            }
        }

        $this->assertSame([], $slack, 'Ratchet ceilings drifted above their files:\n' . \implode("\n", $slack));
    }
}

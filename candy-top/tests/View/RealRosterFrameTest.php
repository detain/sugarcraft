<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\View;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Tests\Support\Harness;

/**
 * S-X04 — real-roster size sweeps.
 *
 * tests/AppTest.php's frame sweep drives the frozen PLACEHOLDER roster;
 * the shipped roster (Panels::standard under --fake, deterministic fakes)
 * reaches layouts the placeholders never exercise — DualSampleGraph in the
 * net box being the CALIBER-flagged <1-geometry hazard that had zero unit
 * lanes. These datasets sweep the real roster down to 1×1 and assert the
 * terminal-size invariant per frame; the golden locks the full 120x40
 * painted roster (regenerate with CANDY_TOP_UPDATE_GOLDENS=1).
 *
 * Degenerate-combo disclosure (the fail-loud option the annotation allows):
 * a probe of every dataset below — plus 97x37, 133x41, 2x2, and the cpu-only
 * alternate combos — throws NOTHING on current disk and paints exact-sized
 * frames; the compact forms landed by S-N06/S-N20/S-X01 hold across the real
 * roster, so no expect-throw roster is recorded and the sweep asserts the
 * size law unconditionally.
 *
 * Goldens: real-roster-120x40.txt is NEW in this row (disclosed); the other
 * fixtures are untouched.
 */
final class RealRosterFrameTest extends TestCase
{
    private string $tz;

    protected function setUp(): void
    {
        $this->tz = date_default_timezone_get();
        date_default_timezone_set('UTC');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->tz);
    }

    /** @return iterable<string, array{int, int, array<string, bool|string>}> */
    public static function frames(): iterable
    {
        yield '120x40 full roster' => [120, 40, []];
        yield '80x24 alternate layout' => [80, 24, ['cpu_bottom' => true, 'mem_below_net' => true, 'proc_left' => true]];
        yield '60x8 mem+net' => [60, 8, ['shown_boxes' => 'mem net']];
        yield '10x3 cpu only' => [10, 3, ['shown_boxes' => 'cpu']];
        yield '44x16 alternate layout' => [44, 16, ['cpu_bottom' => true, 'mem_below_net' => true, 'proc_left' => true]];
        yield '1x1 degenerate' => [1, 1, []];
    }

    /** @param array<string, bool|string> $options */
    #[DataProvider('frames')]
    public function testRealRosterFramesAreExactlyTerminalSized(int $cols, int $rows, array $options): void
    {
        $config = Config::new()->with('color_theme', 'Default');
        foreach ($options as $key => $value) {
            $config = $config->with($key, $value);
        }

        $view = (string) Harness::running($cols, $rows, $config, standardRoster: true)->view();
        $lines = explode("\n", $view);

        $this->assertCount($rows, $lines, 'frame height');
        foreach ($lines as $i => $line) {
            $this->assertSame($cols, Width::string($line), "row {$i} width");
        }
    }

    public function testRealRosterFullFrameGolden(): void
    {
        $grid = implode("\n", Harness::running(120, 40, null, standardRoster: true)->surface()?->plainLines() ?? []) . "\n";

        $this->assertGolden(__DIR__ . '/../fixtures/frames/real-roster-120x40.txt', $grid);
    }

    /** Mirrors FrameSnapshotTest's law: a missing golden fails, never passes silently. */
    private function assertGolden(string $path, string $actual): void
    {
        if (getenv('CANDY_TOP_UPDATE_GOLDENS') === '1') {
            file_put_contents($path, $actual);
            $this->markTestIncomplete("Golden {$path} was (re)written; review the diff.");
        }
        $this->assertFileExists($path, 'Missing golden; regenerate with CANDY_TOP_UPDATE_GOLDENS=1 and review it.');
        $this->assertSame((string) file_get_contents($path), $actual);
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Tick\Tests;

use SugarCraft\Tick\Heartbeat;
use SugarCraft\Tick\Store;
use PHPUnit\Framework\TestCase;

final class StoreTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/sugar-tick-' . bin2hex(random_bytes(4));
        mkdir($this->tmp);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->tmp);
    }

    public function testAppendAndLoadDayRoundTrip(): void
    {
        $s = new Store($this->tmp);
        $day = new \DateTimeImmutable('2026-05-03');
        $hb  = new Heartbeat(
            time:     $day->getTimestamp() + 3600,
            project:  'sugarcraft',
            language: 'php',
            file:     'src/X.php',
            duration: 120,
        );
        $s->append($hb);

        $loaded = $s->loadDay($day);
        $this->assertCount(1, $loaded);
        $this->assertSame('sugarcraft', $loaded[0]->project);
        $this->assertSame('php',        $loaded[0]->language);
        $this->assertSame(120,          $loaded[0]->duration);
    }

    public function testLoadMissingDayReturnsEmpty(): void
    {
        $s = new Store($this->tmp);
        $this->assertSame(
            [],
            $s->loadDay(new \DateTimeImmutable('2026-01-01')),
        );
    }

    public function testLoadRangeMergesAcrossDays(): void
    {
        $s = new Store($this->tmp);
        $a = new \DateTimeImmutable('2026-05-01 12:00');
        $b = new \DateTimeImmutable('2026-05-02 12:00');
        $c = new \DateTimeImmutable('2026-05-03 12:00');
        $s->append(new Heartbeat($a->getTimestamp(), 'p', 'php', '', 60));
        $s->append(new Heartbeat($b->getTimestamp(), 'p', 'php', '', 60));
        $s->append(new Heartbeat($c->getTimestamp(), 'p', 'php', '', 60));

        $range = $s->loadRange(
            new \DateTimeImmutable('2026-05-01'),
            new \DateTimeImmutable('2026-05-03'),
        );
        $this->assertCount(3, $range);
    }

    public function testCorruptLineIsSkipped(): void
    {
        $day = new \DateTimeImmutable('2026-05-03');
        file_put_contents(
            $this->tmp . '/2026-05-03.jsonl',
            json_encode(['time' => 1, 'project' => 'a', 'duration' => 30]) . "\n"
            . "this is not json\n"
            . json_encode(['time' => 2, 'project' => 'b', 'duration' => 45]) . "\n"
        );
        $s = new Store($this->tmp);
        $loaded = $s->loadDay($day);
        $this->assertCount(2, $loaded);
        $this->assertSame('a', $loaded[0]->project);
        $this->assertSame('b', $loaded[1]->project);
    }

    public function testDefaultDirXdgAndFallback(): void
    {
        $origXdg = getenv('XDG_DATA_HOME');
        $origHome = getenv('HOME');

        try {
            // Test XDG_DATA_HOME branch
            putenv('XDG_DATA_HOME=/custom/xdg/data');
            $this->assertSame(
                '/custom/xdg/data/sugar-tick',
                Store::defaultDir(),
            );

            // Test HOME fallback branch
            putenv('XDG_DATA_HOME');
            putenv('HOME=/custom/home');
            $this->assertSame(
                '/custom/home/.local/share/sugar-tick',
                Store::defaultDir(),
            );

            // Test fallback when neither is set (use cwd '.')
            putenv('HOME');
            $this->assertSame(
                './.local/share/sugar-tick',
                Store::defaultDir(),
            );
        } finally {
            // Restore original environment
            if ($origXdg !== false) {
                putenv("XDG_DATA_HOME={$origXdg}");
            } else {
                putenv('XDG_DATA_HOME');
            }
            if ($origHome !== false) {
                putenv("HOME={$origHome}");
            } else {
                putenv('HOME');
            }
        }
    }

    public function testLoadDayMemoizesUntilInvalidate(): void
    {
        $s = new Store($this->tmp);
        $day = new \DateTimeImmutable('2026-05-03');

        // Append initial data
        $hb1 = new Heartbeat($day->getTimestamp(), 'proj1', 'php', 'a.php', 60);
        $s->append($hb1);

        // First loadDay reads from disk
        $loaded1 = $s->loadDay($day);
        $this->assertCount(1, $loaded1);

        // Mutate the file externally (simulate another process writing)
        $file = $this->tmp . '/2026-05-03.jsonl';
        file_put_contents($file, json_encode([
            'time' => $day->getTimestamp(),
            'project' => 'proj2',
            'language' => 'go',
            'file' => 'b.go',
            'duration' => 120,
        ]) . "\n", FILE_APPEND);

        // Without invalidate, second loadDay returns cached (original) data
        $loaded2 = $s->loadDay($day);
        $this->assertCount(1, $loaded2);  // Still only 1 (cached)
        $this->assertSame('proj1', $loaded2[0]->project);

        // After invalidate, loadDay sees the new content
        $s->invalidate();
        $loaded3 = $s->loadDay($day);
        $this->assertCount(2, $loaded3);  // Both heartbeats now visible
    }

    public function testAppendThrowsWhenPreCancelled(): void
    {
        $s = new Store($this->tmp);
        $day = new \DateTimeImmutable('2026-05-03');

        // Create a cancelled token via CancellationSource
        $source = \SugarCraft\Async\CancellationSource::new();
        $source->cancel();
        $token = $source->token();

        $hb = new Heartbeat($day->getTimestamp(), 'proj', 'php', 'a.php', 60);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cancelled');
        $s->append($hb, $token);
    }

    public function testAppendCompletesWhenNotCancelled(): void
    {
        $s = new Store($this->tmp);
        $day = new \DateTimeImmutable('2026-05-03');

        // Create an uncancelled token via CancellationSource
        $source = \SugarCraft\Async\CancellationSource::new();
        $token = $source->token();

        $hb = new Heartbeat($day->getTimestamp(), 'proj', 'php', 'a.php', 60);
        $s->append($hb, $token);

        $loaded = $s->loadDay($day);
        $this->assertCount(1, $loaded);
        $this->assertSame('proj', $loaded[0]->project);
    }

    public function testClampRangeDaysCeilsHugeValues(): void
    {
        // BUG guard: export/gaps must not walk a 100-year range. Reverting the
        // upper clamp makes these return the raw value and fail.
        $this->assertSame(Store::MAX_RANGE_DAYS, Store::clampRangeDays(1_000_000));
        $this->assertSame(Store::MAX_RANGE_DAYS, Store::clampRangeDays(Store::MAX_RANGE_DAYS + 1));
        $this->assertSame(Store::MAX_RANGE_DAYS, Store::clampRangeDays(PHP_INT_MAX));
    }

    public function testClampRangeDaysFloorsAtOne(): void
    {
        $this->assertSame(1, Store::clampRangeDays(0));
        $this->assertSame(1, Store::clampRangeDays(-5));
    }

    public function testClampRangeDaysPassesThroughInRange(): void
    {
        $this->assertSame(7, Store::clampRangeDays(7));
        $this->assertSame(30, Store::clampRangeDays(30));
        $this->assertSame(Store::MAX_RANGE_DAYS, Store::clampRangeDays(Store::MAX_RANGE_DAYS));
    }

    public function testLoadDayStreamsLargeFileWithoutDataLoss(): void
    {
        // PERF/parity guard: loadDay() streams with fgets(); a large multi-line
        // day must round-trip every line identically to the old slurp path.
        $day = new \DateTimeImmutable('2026-05-03');
        $file = $this->tmp . '/2026-05-03.jsonl';
        $fh = fopen($file, 'wb');
        $this->assertNotFalse($fh);
        for ($i = 0; $i < 5000; $i++) {
            fwrite($fh, json_encode([
                'time' => $day->getTimestamp() + $i,
                'project' => 'p',
                'language' => 'php',
                'file' => 'f' . $i . '.php',
                'duration' => 60,
            ]) . "\n");
        }
        fclose($fh);

        $s = new Store($this->tmp);
        $loaded = $s->loadDay($day);
        $this->assertCount(5000, $loaded);
        $this->assertSame('f0.php', $loaded[0]->file);
        $this->assertSame('f4999.php', $loaded[4999]->file);
    }

    public function testAppendUsesExclusiveLockAndLoadDayStreams(): void
    {
        // The LOCK_EX flag and fgets() streaming are not runtime-observable, so
        // assert on the source as a revert-proof seam: dropping LOCK_EX or going
        // back to file_get_contents() slurping fails this guard.
        $src = (string) file_get_contents(dirname(__DIR__) . '/src/Store.php');
        $this->assertStringContainsString('FILE_APPEND | LOCK_EX', $src);
        $this->assertStringContainsString('fgets(', $src);
        $this->assertStringNotContainsString('file_get_contents(', $src);
    }
}

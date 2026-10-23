<?php

declare(strict_types=1);

namespace SugarCraft\CodeTime\Tests;

use SugarCraft\CodeTime\Heartbeat;
use SugarCraft\CodeTime\Storage\HeartbeatReader;
use SugarCraft\CodeTime\Store;
use PHPUnit\Framework\TestCase;

final class StoreTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/candy-codetime-' . bin2hex(random_bytes(4));
        mkdir($this->tmp);
    }

    protected function tearDown(): void
    {
        $this->removeDirRecursive($this->tmp);
    }

    private function removeDirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->removeDirRecursive($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
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
                '/custom/xdg/data/candy-codetime',
                Store::defaultDir(),
            );

            // Test HOME fallback branch
            putenv('XDG_DATA_HOME');
            putenv('HOME=/custom/home');
            $this->assertSame(
                '/custom/home/.local/share/candy-codetime',
                Store::defaultDir(),
            );

            // Test fallback when neither is set (use cwd '.')
            putenv('HOME');
            $this->assertSame(
                './.local/share/candy-codetime',
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

    public function testAppendCreatesDirWhenMissing(): void
    {
        // Mirrors Store::append() lines 131-133: when dir does not exist,
        // mkdir is called to create it before writing the file.
        $nestedDir = $this->tmp . '/nested/deep/path';
        $this->assertFalse(is_dir($nestedDir));

        $s = new Store($nestedDir);
        $day = new \DateTimeImmutable('2026-05-03');
        $hb = new Heartbeat($day->getTimestamp(), 'proj', 'php', 'a.php', 60);

        $s->append($hb);

        $this->assertTrue(is_dir($nestedDir));
        $loaded = $s->loadDay($day);
        $this->assertCount(1, $loaded);
    }
    public function testSatisfiesTheSharedReaderContract(): void
    {
        $this->assertInstanceOf(HeartbeatReader::class, new Store($this->tmp));
    }

    public function testAppendFailsLoudlyWhenDataPathCannotBecomeADirectory(): void
    {
        $blocked = $this->tmp . '/blocked-dir';
        file_put_contents($blocked, 'a file where a directory must be');

        $s = new Store($blocked);
        try {
            $s->append(new Heartbeat(time(), 'p', 'php', 'a.php', 60));
            $this->fail('append over an un-createable data dir must throw');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('cannot create data directory', $e->getMessage());
        }
    }

    public function testAppendSecuresOwnerPrivateModes(): void
    {
        $dir = $this->tmp . '/store';
        mkdir($dir, 0o755);
        $s = new Store($dir);
        $s->append(new Heartbeat(time(), 'p', 'php', 'a.php', 60));

        clearstatcache();
        $this->assertSame(0o700, fileperms($dir) & 0o777, 'data dir must be tightened to 0700');
        $file = $dir . '/' . date('Y-m-d') . '.jsonl';
        $this->assertSame(0o600, fileperms($file) & 0o777, 'day file must be owner-private');
    }

    public function testTightensPreExistingLooseDayFile(): void
    {
        $dir = $this->tmp . '/store2';
        mkdir($dir, 0o700);
        $file = $dir . '/' . date('Y-m-d') . '.jsonl';
        file_put_contents($file, "stale line\n");
        chmod($file, 0o644);

        $s = new Store($dir);
        $s->append(new Heartbeat(time(), 'p', 'php', 'b.php', 60));

        clearstatcache();
        $this->assertSame(0o600, fileperms($file) & 0o777);
        $this->assertStringContainsString('b.php', (string) file_get_contents($file));
    }

    public function testAppendRefusesAnUnencodableHeartbeatInsteadOfWritingGarbage(): void
    {
        $s = new Store($this->tmp);
        try {
            $s->append(new Heartbeat(time(), "pr\xffo", 'php', 'a.php', 60));
            $this->fail('invalid UTF-8 must raise, not write a blank/broken line');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('could not be encoded', $e->getMessage());
        }
        $this->assertFileDoesNotExist($this->tmp . '/' . date('Y-m-d') . '.jsonl');
    }

    public function testLoadDayDropsOversizedLinesButKeepsTheirNeighbours(): void
    {
        $day  = new \DateTimeImmutable('2026-05-04');
        $file = $this->tmp . '/2026-05-04.jsonl';
        $good = static fn(string $project): string => json_encode([
            'time' => $day->getTimestamp() + 60, 'project' => $project,
            'language' => 'php', 'file' => $project . '.php', 'duration' => 60, 'tags' => [],
        ]);
        $monster = str_repeat('x', Store::MAX_LINE_BYTES + 4096) . "\n";
        file_put_contents($file, $good('first') . "\n" . $monster . $good('last') . "\n");

        $rows = (new Store($this->tmp))->loadDay($day);
        $this->assertCount(2, $rows);
        $this->assertSame(['first', 'last'], array_map(static fn($r) => $r->project, $rows));
    }

    public function testEnsurePrivateDirTightensAnExistingLooseDirectory(): void
    {
        $dir = $this->tmp . '/loose';
        mkdir($dir, 0o755);
        Store::ensurePrivateDir($dir);
        clearstatcache();
        $this->assertSame(0o700, fileperms($dir) & 0o777);
    }
public function testAppendValidatesTheFullWrittenLineCount(): void
    {
        // Structural pin for the write-count guard: the branch is not
        // deterministically reachable as an unprivileged user everywhere
        // (some kernels tolerate RLIMIT_FSIZE overshoot — see the probe in
        // testAppendReportsAFailedWriteLoudly), so removing the check must
        // still redden a test. The guarded body must count the whole line,
        // not merely reject `false`.
        $src = (string) file_get_contents(dirname(__DIR__) . '/src/Store.php');
        $start = strpos($src, '$written = @file_put_contents(');
        $this->assertNotFalse($start, 'append() must write via @file_put_contents with errors surfaced for the message');
        $tail = substr($src, $start, 1400);
        $this->assertStringContainsString('if ($written !== strlen($line)) {', $tail,
            'the write check must catch partial writes too, not just === false');
        $this->assertStringContainsString('failed to append heartbeat', $tail);
    }

    public function testAppendReportsAFailedWriteLoudly(): void
    {
        // The portable way to make file_put_contents() actually fail as an
        // ordinary user: a child under RLIMIT_FSIZE writing past the cap.
        // SIGXFSZ is ignored (disposition rides exec), so a failing syscall
        // returns an error/short count instead of killing the writer — the
        // silent-loss window the count check closes. On kernels that do not
        // enforce the limit (measured on this devbox), the probe below skips
        // and the structural pin above carries the mutation guard.
        $probeDir = $this->tmp . '/probe';
        $probeOut = [];
        $probeRc = 0;
        exec(
            'bash -c ' . escapeshellarg(
                'trap "" XFSZ; ulimit -f 4; head -c 5000 /dev/zero > ' .
                escapeshellarg($probeDir . '.probe') . ' 2>/dev/null; stat -c%s ' .
                escapeshellarg($probeDir . '.probe')
            ),
            $probeOut,
            $probeRc,
        );
        @unlink($probeDir . '.probe');
        if ($probeRc !== 0 || (int) ($probeOut[0] ?? 0) > 4096) {
            $this->markTestSkipped('RLIMIT_FSIZE overshoot is not enforced on this kernel');
        }

        $dir = $this->tmp . '/rlimit-store';
        $child = $this->tmp . '/rlimit-child.php';
        file_put_contents($child, implode("\n", [
            '<?php',
            "require getenv('CODETIME_AUTOLOAD');",
            '$s = new SugarCraft\CodeTime\Store(getenv("CODETIME_DIR"));',
            '$hb = new SugarCraft\CodeTime\Heartbeat(time(), "p", "php", "a.php", 60, [str_repeat("t", 5000)]);',
            'try { $s->append($hb); echo "UNLUCKY\\n"; }',
            'catch (RuntimeException $e) { echo "CAUGHT ", $e->getMessage(), "\\n"; }',
        ]) . "\n");

        $cmd = sprintf(
            'trap "" XFSZ; ulimit -f 4; CODETIME_AUTOLOAD=%s CODETIME_DIR=%s %s %s',
            escapeshellarg(dirname(__DIR__) . '/vendor/autoload.php'),
            escapeshellarg($dir),
            escapeshellarg(PHP_BINARY),
            escapeshellarg($child),
        );
        $out = [];
        $rc = 0;
        exec('bash -c ' . escapeshellarg($cmd), $out, $rc);

        $this->assertSame(0, $rc, implode("\n", $out));
        $joined = implode("\n", $out);
        $this->assertStringContainsString('CAUGHT', $joined, 'a failed append must throw, never return silently');
        $this->assertStringContainsString('failed to append heartbeat', $joined);
    }
}

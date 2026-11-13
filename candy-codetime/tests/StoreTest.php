<?php

declare(strict_types=1);

namespace SugarCraft\CodeTime\Tests;

use SugarCraft\CodeTime\Tests\Support\DrainPipes;
use SugarCraft\CodeTime\Heartbeat;
use SugarCraft\CodeTime\Storage\HeartbeatReader;
use SugarCraft\CodeTime\Store;
use PHPUnit\Framework\TestCase;

final class StoreTest extends TestCase
{

    use DrainPipes;

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
        // Mirrors Store::append() seeding its directory through the same
        // ensurePrivateDir guard it shares with the other doors: when the
        // dir does not exist, mkdir creates it before the file is written.
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
        // One clock read: the row and its expected day file derive from the
        // same instant, so a midnight flip mid-test cannot orphan it (041).
        $now = time();
        $s = new Store($dir);
        $s->append(new Heartbeat($now, 'p', 'php', 'a.php', 60));

        clearstatcache();
        $this->assertSame(0o700, fileperms($dir) & 0o777, 'data dir must be tightened to 0700');
        $file = $dir . '/' . date('Y-m-d', $now) . '.jsonl';
        $this->assertSame(0o600, fileperms($file) & 0o777, 'day file must be owner-private');
    }

    public function testTightensPreExistingLooseDayFile(): void
    {
        $dir = $this->tmp . '/store2';
        mkdir($dir, 0o700);
        $now = time();
        $file = $dir . '/' . date('Y-m-d', $now) . '.jsonl';
        file_put_contents($file, "stale line\n");
        chmod($file, 0o644);

        $s = new Store($dir);
        $s->append(new Heartbeat($now, 'p', 'php', 'b.php', 60));

        clearstatcache();
        $this->assertSame(0o600, fileperms($file) & 0o777);
        $this->assertStringContainsString('b.php', (string) file_get_contents($file));
    }

    public function testAppendRefusesAnUnencodableHeartbeatInsteadOfWritingGarbage(): void
    {
        $now = time();
        $s = new Store($this->tmp);
        try {
            $s->append(new Heartbeat($now, "pr\xffo", 'php', 'a.php', 60));
            $this->fail('invalid UTF-8 must raise, not write a blank/broken line');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('could not be encoded', $e->getMessage());
        }
        $this->assertFileDoesNotExist($this->tmp . '/' . date('Y-m-d', $now) . '.jsonl');
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

    public function testDrainedLinesAreAnnouncedOnceWithTheirCounts(): void
    {
        // CT-S19: the over-cap drain and the json_decode drain were silent
        // data loss. Each is now one counted notice per file per read, and
        // the first offender's position is named.
        $day    = new \DateTimeImmutable('2026-05-05');
        $script = sys_get_temp_dir() . '/candy-codetime-drain-' . bin2hex(random_bytes(4)) . '.php';
        $good   = static fn(string $p): string => json_encode([
            'time' => (new \DateTimeImmutable('2026-05-05'))->getTimestamp() + 60,
            'project' => $p, 'language' => 'php', 'file' => $p . '.php', 'duration' => 60, 'tags' => [],
        ]);
        $lines = $good('first') . "\n"
            . str_repeat('x', Store::MAX_LINE_BYTES + 100) . "\n"
            . "{oops\n"
            . 'not even close' . "\n"
            . $good('last') . "\n";
        file_put_contents($this->tmp . '/2026-05-05.jsonl', $lines);
        file_put_contents($script, "<?php\n"
            . 'require ' . var_export(__DIR__ . '/../vendor/autoload.php', true) . ";\n"
            . '$store = new \\SugarCraft\\CodeTime\\Store(' . var_export($this->tmp, true) . ");\n"
            . '$rows = $store->loadDay(new \\DateTimeImmutable(' . var_export('2026-05-05', true) . "));\n"
            . 'fwrite(STDOUT, (string) count($rows));' . "\n");
        $proc = proc_open([PHP_BINARY, $script], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        [$out, $err] = self::drainPair($pipes[1], $pipes[2]);
        proc_close($proc);
        $this->assertSame('2', $out);
        $this->assertSame(2, substr_count($err, "\n"), 'exactly two counted notices, not silence and not a pile-up: ' . $err);
        $this->assertStringContainsString('dropped 1 over-cap line(s)', $err);
        $this->assertStringContainsString('dropped 2 unparseable line(s)', $err);
        $this->assertStringContainsString('(first at line 3)', $err);
        $this->assertStringContainsString('2026-05-05.jsonl', $err);
        unlink($script);
    }

    public function testSilencedStoreKeepsTheTerminalCleanButTheCountsStillDrain(): void
    {
        // CT-S19: while the alt screen is live the store must put nothing on
        // stderr, yet the dashboard footer still needs the numbers.
        $day = new \DateTimeImmutable('2026-05-06');
        file_put_contents($this->tmp . '/2026-05-06.jsonl', "{oops\n{again\n");
        $script = sys_get_temp_dir() . '/candy-codetime-silent-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($script, "<?php\n"
            . 'require ' . var_export(__DIR__ . '/../vendor/autoload.php', true) . ";\n"
            . '$store = new \\SugarCraft\\CodeTime\\Store(' . var_export($this->tmp, true) . ");\n"
            . '$store->silenceDiagnostics();' . "\n"
            . '$store->loadDay(new \\DateTimeImmutable(' . var_export('2026-05-06', true) . "));\n"
            . '$chmod = ' . var_export($this->tmp . '/2026-05-07.jsonl', true) . ";\n"
            . 'file_put_contents($chmod, "x\n");' . "\n"
            . 'chmod($chmod, 0o000);' . "\n"
            . '$store->loadDay(new \\DateTimeImmutable(' . var_export('2026-05-07', true) . "));\n"
            . 'chmod($chmod, 0o600);' . "\n"
            . '$notes = $store->drainDiagnostics();' . "\n"
            . 'fwrite(STDOUT, $notes[\'dropped\'] . \'|\' . $notes[\'unreadable\']);' . "\n");
        $proc = proc_open([PHP_BINARY, $script], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        [$out, $err] = self::drainPair($pipes[1], $pipes[2]);
        proc_close($proc);
        $this->assertSame('', $err, 'the silent lane keeps every byte off stderr: ' . $err);
        $this->assertSame('2|1', $out, 'two dropped lines and one unreadable day still counted');
        $this->assertFileExists($this->tmp . '/2026-05-07.jsonl');
        unlink($script);
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

    /**
     * Racer regression (review M11): the create step used to be check-then-
     * create, so two appenders reaching the same brand-new day file raced
     * between is_file() and fopen() — the loser threw "cannot create" and its
     * heartbeat was gone. With several writers hitting many fresh days
     * concurrently that window is impossible to dodge reliably; attempt-first
     * with an existence re-check only on failure closes it entirely.
     */
    public function testTheFirstAppendOfADaySurvivesAConcurrentAppender(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork is required to race appenders');
        }

        $dir = $this->tmp . '/race-store';
        $lostDir = $this->tmp . '/race-lost';
        mkdir($dir);
        mkdir($lostDir);

        $base = strtotime('2024-05-01 12:00:00 UTC');
        $days = 40;
        $writers = 3;
        $pids = [];
        for ($w = 0; $w < $writers; $w++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('pcntl_fork failed');
            }
            if ($pid === 0) {
                // Child: one beat per fresh day file. A lost append drops a
                // sentinel and dies non-zero — assertions stay in the parent,
                // a fork must never run PHPUnit's shutdown path.
                $store = new Store($dir);
                try {
                    for ($i = 0; $i < $days; $i++) {
                        $store->append(new Heartbeat(
                            $base + $i * 86400,
                            'writer-' . $w,
                            'php',
                            'a.php',
                            60,
                        ));
                    }
                } catch (\RuntimeException $e) {
                    file_put_contents($lostDir . '/writer-' . $w . '.lost', $e->getMessage());
                    exit(1);
                }
                exit(0);
            }
            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            $status = 0;
            pcntl_waitpid($pid, $status);
        }

        $lost = array_values(array_filter(
            scandir($lostDir) ?: [],
            static fn(string $f): bool => str_ends_with($f, '.lost'),
        ));
        $this->assertSame(
            [],
            $lost,
            'a racing appender lost its first write of a day: ' . implode(' | ', array_map(
                static fn(string $f): string => (string) file_get_contents($lostDir . '/' . $f),
                $lost,
            )),
        );

        $lines = 0;
        for ($i = 0; $i < $days; $i++) {
            $file = $dir . '/' . gmdate('Y-m-d', $base + $i * 86400) . '.jsonl';
            $this->assertFileExists($file);
            $lines += count(file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
        }
        $this->assertSame($days * $writers, $lines, 'every raced heartbeat must be on disk exactly once');
    }

    public function testAnUnreadableDayFileWarnsOnStderrAndYieldsAnEmptyDay(): void
    {
        // m-12: a day file that exists but cannot be opened used to vanish in
        // total silence. The warning is one line on stderr; the reader's
        // shape (empty day, no throw) is unchanged. File modes are asserted
        // from a child process, and root would shrug off chmod 000.
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root bypasses file permission modes');
        }

        $day = '2024-05-05';
        $file = $this->tmp . '/' . $day . '.jsonl';
        file_put_contents($file, json_encode([
            'time' => strtotime($day . ' 10:00:00'), 'project' => 'p',
            'language' => 'php', 'file' => 'a.php', 'duration' => 60, 'tags' => [],
        ]) . "\n");

        // Positive control: the same child sees the row while the file is openable.
        [$out, $err, $exit] = $this->runLoadDayChild($day);
        $this->assertSame(0, $exit, $err);
        $this->assertSame('1', $out, $err);
        $this->assertSame('', $err, 'a readable day must print no warning');

        chmod($file, 0o000);
        try {
            [$out, $err, $exit] = $this->runLoadDayChild($day);
            $this->assertSame(0, $exit, $err);
            $this->assertSame('0', $out, 'the unreadable day still reads as empty');
            $this->assertStringContainsString('warning', $err);
            $this->assertStringContainsString($day . '.jsonl', $err);
            $this->assertStringContainsString('cannot read day file', $err);
            $this->assertStringEndsWith("\n", $err, 'the warning is one line');
            $this->assertSame(1, substr_count($err, "\n"), 'exactly one line, no per-attempt pile-up within one read');
        } finally {
            chmod($file, 0o600); // restore so tearDown can unlink
        }
    }

    /** @return array{0:string,1:string,2:int} stdout, stderr, exit */
    private function runLoadDayChild(string $day): array
    {
        // A planted script beats php -r: no shell quoting can eat the $sigs.
        $script = sys_get_temp_dir() . '/candy-codetime-loadday-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($script, "<?php\n"
            . 'require ' . var_export(__DIR__ . '/../vendor/autoload.php', true) . ";\n"
            . '$store = new \\SugarCraft\\CodeTime\\Store(' . var_export($this->tmp, true) . ");\n"
            . '$rows = $store->loadDay(new \\DateTimeImmutable(' . var_export($day, true) . "));\n"
            . 'fwrite(STDOUT, (string) count($rows));' . "\n");
        $proc = proc_open([PHP_BINARY, $script], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        [$out, $err] = self::drainPair($pipes[1], $pipes[2]);
        $exit = proc_close($proc);
        unlink($script);
        return [$out, $err, $exit];
    }

    public function testSweepTightensLooseDirsDayFilesDatabasesAndArchives(): void
    {
        // Plant the exact shapes a plugin-direct `>>` or a migration move
        // leaves behind: loose dir, loose day file, loose db, loose archive.
        // Any dated name qualifies: the sweep globs *.jsonl, never today's.
        $dayFile = $this->tmp . '/2026-01-02.jsonl';
        file_put_contents($dayFile, '{"time":1,"project":"p","language":"php","file":"f","duration":60,"tags":[]}' . "\n");
        chmod($this->tmp, 0o755);
        chmod($dayFile, 0o644);
        file_put_contents($this->tmp . '/candy-codetime.db', 'not-really-sqlite-but-mode-matters');
        chmod($this->tmp . '/candy-codetime.db', 0o644);
        $backupDir = $this->tmp . '-backups';
        mkdir($backupDir);
        chmod($backupDir, 0o755);
        $archived = $backupDir . '/candy-codetime-2026-01-02.jsonl';
        file_put_contents($archived, "x\n");
        chmod($archived, 0o644);

        (new Store($this->tmp))->enforcePrivateModes();

        clearstatcache();
        $this->assertSame(0o700, fileperms($this->tmp) & 0o777);
        $this->assertSame(0o600, fileperms($dayFile) & 0o777);
        $this->assertSame(0o600, fileperms($this->tmp . '/candy-codetime.db') & 0o777);
        $this->assertSame(0o700, fileperms($backupDir) & 0o777);
        $this->assertSame(0o600, fileperms($archived) & 0o777);
    }

    public function testSweepOnAMissingDirectoryIsANoOp(): void
    {
        (new Store($this->tmp . '/never-created'))->enforcePrivateModes();
        $this->assertDirectoryDoesNotExist($this->tmp . '/never-created');
    }

    public function testATimelessRowIsDroppedLikeGarbageNotHydratedWithNow(): void
    {
        // CT-S15: storage readers never invent timestamps — a decoded row
        // without `time` shares the corrupt-line lane (counted, named).
        $dir = $this->tmp . '/timeless-' . bin2hex(random_bytes(4));
        mkdir($dir, 0o700);
        $good = json_encode(['time' => 1775000000, 'project' => 'p', 'language' => 'l', 'file' => 'f', 'duration' => 5]);
        $ghost = json_encode(['project' => 'x', 'language' => 'l', 'file' => 'f', 'duration' => 5]);
        file_put_contents($dir . '/2026-05-05.jsonl', $good . "\n" . $ghost . "\n");
        $store = new Store($dir);
        $rows = $store->loadDay(new \DateTimeImmutable('2026-05-05'));
        $this->assertCount(1, $rows);
        $this->assertSame(1775000000, $rows[0]->time);
        $this->assertSame(1, $store->drainDiagnostics()['dropped']);
    }

    public function testTheDayCacheRotatesAndStopsAtTheWindowCeiling(): void
    {
        // CT-S16: every other growth surface carries a constant; the memo is
        // now the last one. The cap equals the widest servable window, days
        // served rotate to the tail, and the stale head ages out.
        $cap = Store::DAY_CACHE_MAX_DAYS;
        $dir = $this->tmp . '/lru-' . bin2hex(random_bytes(4));
        mkdir($dir, 0o700);
        $epoch = new \DateTimeImmutable('2000-01-01');
        for ($i = 0; $i <= $cap + 2; $i++) {
            $day = $epoch->modify('+' . $i . ' days');
            file_put_contents($dir . '/' . $day->format('Y-m-d') . '.jsonl',
                json_encode(['time' => $day->getTimestamp(), 'project' => 'p', 'language' => 'l', 'file' => 'f', 'duration' => 1]) . "\n");
        }
        $store = new Store($dir);
        $probe = new \ReflectionProperty(Store::class, 'dayCache');
        for ($i = 0; $i <= $cap + 2; $i++) {
            $store->loadDay($epoch->modify('+' . $i . ' days'));
        }
        $cache = $probe->getValue($store);
        $this->assertCount($cap, $cache, 'the memo never exceeds the widest servable window');
        $this->assertArrayNotHasKey($epoch->format('Y-m-d'), $cache, 'the stale head aged out');
        $this->assertArrayNotHasKey($epoch->modify('+2 days')->format('Y-m-d'), $cache, 'the flood evicted exactly the days past the cap');
        $this->assertArrayHasKey($epoch->modify('+3 days')->format('Y-m-d'), $cache, 'the eviction line is exactly the cap back from the tail');
        $store->loadDay($epoch->modify('+3 days'));
        $this->assertSame($epoch->modify('+3 days')->format('Y-m-d'), array_key_last($probe->getValue($store)), 'a served day rotates to the protected tail');
    }

    // CT-S42: the forget door on the file backend.

    public function testForgetRemovesMatchingLinesAndKeepsOthersByteIdentical(): void
    {
        $secret = json_encode(['time' => 1719000000, 'project' => 'secret', 'language' => 'php', 'file' => '/home/dev/a.php', 'duration' => 60, 'tags' => []]);
        $keep = json_encode(['time' => 1719000100, 'project' => 'public', 'language' => 'php', 'file' => '/home/dev/b.php', 'duration' => 60, 'tags' => []]);
        $garbage = 'this line is not json at all';
        $timeless = '{"project": "secret", "language": "php", "file": "/home/dev/c.php", "duration": 60}';
        $file = $this->tmp . '/2026-05-03.jsonl';
        file_put_contents($file, $secret . "\n" . $keep . "\n" . $garbage . "\n" . $timeless . "\n");

        $store = new Store($this->tmp);
        $drops = static fn (Heartbeat $hb): bool => $hb->project === 'secret';

        $this->assertSame(1, $store->countMatched($drops));
        $this->assertSame(1, $store->forget($drops));

        $lines = file($file, FILE_IGNORE_NEW_LINES);
        $this->assertSame([$keep, $garbage, $timeless], $lines, 'survivors stay byte for byte; the unprovable timeless row survives too');
    }

    public function testForgetUnlinksAFullyEmptiedDayAndLeavesUntouchedDaysAlone(): void
    {
        $line = static fn (int $t, string $project): string => json_encode(['time' => $t, 'project' => $project, 'language' => 'php', 'file' => '/x.php', 'duration' => 60, 'tags' => []]);
        $gone = $this->tmp . '/2026-05-03.jsonl';
        $kept = $this->tmp . '/2026-05-04.jsonl';
        file_put_contents($gone, $line(1719000000, 'secret') . "\n");
        $keptBytes = $line(1719003600, 'public') . "\n";
        file_put_contents($kept, $keptBytes);

        $store = new Store($this->tmp);
        $removed = $store->forget(static fn (Heartbeat $hb): bool => $hb->project === 'secret');

        $this->assertSame(1, $removed);
        $this->assertFileDoesNotExist($gone);
        $this->assertStringEqualsFile($kept, $keptBytes, 'a day without matches is never rewritten');
    }

    public function testForgetRewritesUnderThePrivateModeLaw(): void
    {
        $secret = json_encode(['time' => 1719000000, 'project' => 'secret', 'language' => 'php', 'file' => '/x.php', 'duration' => 60, 'tags' => []]);
        $keep = json_encode(['time' => 1719000100, 'project' => 'public', 'language' => 'php', 'file' => '/y.php', 'duration' => 60, 'tags' => []]);
        $file = $this->tmp . '/2026-05-03.jsonl';
        file_put_contents($file, $secret . "\n" . $keep . "\n");
        chmod($file, 0644);

        $store = new Store($this->tmp);
        $this->assertSame(1, $store->forget(static fn (Heartbeat $hb): bool => $hb->project === 'secret'));

        clearstatcache();
        $this->assertSame(0600, fileperms($file) & 0777, 'the rewrite lands tightened, never loose at the canonical name');
    }

    // ---- CT-S56 (finding 072): the loadRange beat budget.

    public function testADenseRangeStopsAtTheBeatBudgetWithALoudPartialNote(): void
    {
        $this->seedDenseWindow();

        $first = $this->runRangeChild('5');
        $this->assertSame('5', $first[0], 'exactly the budget is served');
        $this->assertStringContainsString('warning', $first[1]);
        $this->assertStringContainsString('stopped reading this window at 5 beats', $first[1]);
        $this->assertStringContainsString('partial window', $first[1]);
        $this->assertSame(1, substr_count($first[1], "\n"), 'the note is one line');

        // The alt-screen door: a silenced store answers the same count with
        // total stderr silence (the dashboard collects diagnostics instead).
        $second = $this->runRangeChild('5', silence: true);
        $this->assertSame('5', $second[0]);
        $this->assertSame('', $second[1]);

        // The production budget carries this window whole and says nothing.
        $third = $this->runRangeChild('default');
        $this->assertSame('9', $third[0]);
        $this->assertSame('', $third[1]);

        // The budget itself passes a door: zero or negative can never mean
        // "read nothing" quietly.
        try {
            new Store($this->tmp, 0);
            $this->fail('a zero beat budget must be refused at the door');
        } catch (\InvalidArgumentException $error) {
            $this->assertStringContainsString('at least 1', $error->getMessage());
        }
    }

    private function seedDenseWindow(): void
    {
        foreach (['2024-05-31', '2024-06-01', '2024-06-02'] as $hour => $day) {
            $lines = '';
            for ($i = 0; $i < 3; $i++) {
                $lines .= json_encode([
                    'time' => strtotime($day . ' 1' . $hour . ':00:00'),
                    'project' => 'budget-proj',
                    'language' => 'php',
                    'file' => '/srv/budget/x.php',
                    'duration' => 60,
                    'tags' => [],
                ], JSON_THROW_ON_ERROR) . "\n";
            }
            file_put_contents($this->tmp . '/' . $day . '.jsonl', $lines);
        }
    }

    /**
     * @return array{0:string,1:string,2:int} stdout, stderr, exit code
     */
    private function runRangeChild(string $budget, bool $silence = false): array
    {
        $ctor = $budget === 'default'
            ? 'new \\SugarCraft\\CodeTime\\Store(' . var_export($this->tmp, true) . ')'
            : 'new \\SugarCraft\\CodeTime\\Store(' . var_export($this->tmp, true) . ', ' . $budget . ')';
        $script = sys_get_temp_dir() . '/candy-codetime-range-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($script, "<?php\n"
            . 'require ' . var_export(__DIR__ . '/../vendor/autoload.php', true) . ";\n"
            . '$store = ' . $ctor . ";\n"
            . ($silence ? '$store->silenceDiagnostics();' . "\n" : '')
            . '$rows = $store->loadRange(new \\DateTimeImmutable("2024-05-31"), new \\DateTimeImmutable("2024-06-02"));' . "\n"
            . 'fwrite(STDOUT, (string) count($rows));' . "\n");
        $proc = proc_open([PHP_BINARY, $script], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        [$out, $err] = self::drainPair($pipes[1], $pipes[2]);
        $exit = proc_close($proc);
        unlink($script);
        return [$out, $err, $exit];
    }

}

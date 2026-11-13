<?php

declare(strict_types=1);

namespace SugarCraft\CodeTime\Tests\Storage;

use PHPUnit\Framework\TestCase;
use SugarCraft\CodeTime\Heartbeat;
use SugarCraft\CodeTime\Milestone;
use SugarCraft\CodeTime\Storage\HeartbeatReader;
use SugarCraft\CodeTime\Storage\SqliteBackend;

final class SqliteBackendTest extends TestCase
{
    private string $dbPath;
    private SqliteBackend $backend;

    protected function setUp(): void
    {
        if (!extension_loaded('sqlite3')) {
            $this->markTestSkipped('sqlite3 extension not loaded');
        }
        $this->dbPath = sys_get_temp_dir() . '/candy-codetime-test-' . uniqid() . '.db';
        $this->backend = new SqliteBackend($this->dbPath);
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            unlink($this->dbPath);
        }
        // The WAL posture leaves sidecars behind; the temp dir stays clean.
        foreach (['-wal', '-shm'] as $suffix) {
            $sidecar = $this->dbPath . $suffix;
            if (is_file($sidecar)) {
                unlink($sidecar);
            }
        }
    }

    public function testANonDatabaseFileAnswersWithOneOwnedLineAndNoPhpWarnings(): void
    {
        // CT-S20 probe: a foreign .db used to print the uncatchable PHP
        // warning stack (exec() is not a receipt) and only die on the first
        // query. Construction itself now fails on ONE owned line, warning-
        // free — verified in a child because phpunit's own error handler is
        // not the surface users run against.
        // A fresh path: setUp's real db left WAL sidecars beside its file,
        // and a wal replay would heal planted garbage into a usable db.
        $bad = $this->dbPath . '-foreign';
        file_put_contents($bad, 'this is not sqlite at all');
        $script = sys_get_temp_dir() . '/candy-codetime-notdb-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($script, "<?php\n"
            . 'require ' . var_export(__DIR__ . '/../../vendor/autoload.php', true) . ";\n"
            . 'try {'
            . 'new \\SugarCraft\\CodeTime\\Storage\\SqliteBackend(' . var_export($bad, true) . ');'
            . 'fwrite(STDOUT, \'constructed\');'
            . '} catch (\\Throwable $e) {'
            . 'fwrite(STDOUT, $e->getMessage());'
            . '}' . "\n");
        $proc = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        proc_close($proc);
        unlink($script);
        $this->assertStringContainsString('is not a usable candy-codetime database', $out);
        $this->assertSame('', $err, 'zero PHP Warning bytes on stderr: ' . $err);
        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($bad . $suffix);
        }
    }

    public function testAWriteProtectedDestinationCopiesToOneOwnedLine(): void
    {
        // CT-S20 probe (third leg): AutoBackup's copy() failure is a checked
        // throw; the raw copy() warning beside it was the leak.
        $dataDir = $this->dbPath . '-seed-dir';
        $backupDir = $this->dbPath . '-locked-backups';
        mkdir($dataDir);
        mkdir($backupDir, 0o500); // readable, not writable
        file_put_contents($dataDir . '/2024-01-01.jsonl', "x\n");
        touch($dataDir . '/2024-01-01.jsonl', time() - 90 * 86400);
        $script = sys_get_temp_dir() . '/candy-codetime-lockeddest-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($script, "<?php\n"
            . 'require ' . var_export(__DIR__ . '/../../vendor/autoload.php', true) . ";\n"
            . '$backup = new \\SugarCraft\\CodeTime\\Backup\\AutoBackup(' . var_export($backupDir, true) . ', [' . var_export($dataDir, true) . "]);\n"
            . 'try { $backup->rotate(30); fwrite(STDOUT, \'rotated\'); }'
            . ' catch (\\Throwable $e) { fwrite(STDOUT, $e->getMessage()); }' . "\n");
        $proc = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        proc_close($proc);
        chmod($backupDir, 0o700); // let tearDown sweep
        unlink($script);
        if ($out === 'rotated') {
            $this->markTestSkipped('this user (root?) cannot be denied a write into a 0500 directory');
        }
        $this->assertStringContainsString('failed to copy', $out);
        $this->assertSame('', $err, 'zero PHP Warning bytes on stderr: ' . $err);
        foreach (glob($backupDir . '/*') ?: [] as $stray) {
            unlink($stray);
        }
        rmdir($backupDir);
        unlink($dataDir . '/2024-01-01.jsonl');
        rmdir($dataDir);
    }

    public function testInsertRefusesInvalidUtf8BeforeItCanPoisonTheStore(): void
    {
        $overrides = [
            'project'  => ['project' => "\xff\xfebad"],
            'language' => ['language' => "\xffbad"],
            'file'     => ['file' => "src/\xff.php"],
        ];
        foreach ($overrides as $leg => $override) {
            $hb = new Heartbeat(...array_merge([
                'time'     => 1718900000,
                'project'  => 'ok',
                'language' => 'php',
                'file'     => 'src/Ok.php',
                'duration' => 60,
                'tags'     => [],
            ], $override));
            try {
                $this->backend->insert($hb);
                $this->fail("the $leg bind leg must refuse invalid UTF-8, not store it");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('not valid UTF-8', $e->getMessage());
            }
        }
        $this->assertCount(0, $this->backend->query(0, 2000000000), 'a refused insert leaves nothing behind');
    }

    public function testInsertAndQuery(): void
    {
        $hb = new Heartbeat(
            time: 1718900000,
            project: 'test-project',
            language: 'php',
            file: 'src/Test.php',
            duration: 120,
            tags: ['feature', 'refactor'],
        );

        $this->backend->insert($hb);

        $results = $this->backend->query(1718899000, 1719000000);
        $this->assertCount(1, $results);
        $this->assertSame('test-project', $results[0]->project);
        $this->assertSame('php', $results[0]->language);
        $this->assertSame('src/Test.php', $results[0]->file);
        $this->assertSame(120, $results[0]->duration);
        $this->assertSame(['feature', 'refactor'], $results[0]->tags);
    }

    public function testQueryRangeBoundary(): void
    {
        $hb = new Heartbeat(
            time: 1718900000,
            project: 'test-project',
            language: 'php',
            file: 'src/Test.php',
        );
        $this->backend->insert($hb);

        // Outside range
        $this->assertCount(0, $this->backend->query(0, 1718899999));
        // Inside range
        $this->assertCount(1, $this->backend->query(1718900000, 1719000000));
    }

    public function testMultipleHeartbeatsOrderedByTime(): void
    {
        $hb1 = new Heartbeat(time: 1718900000, project: 'p1', language: 'php', file: 'a.php');
        $hb2 = new Heartbeat(time: 1718900100, project: 'p2', language: 'py', file: 'b.php');
        $hb3 = new Heartbeat(time: 1718900200, project: 'p3', language: 'rs', file: 'c.php');

        $this->backend->insert($hb2);
        $this->backend->insert($hb3);
        $this->backend->insert($hb1);

        $results = $this->backend->query(0, PHP_INT_MAX);
        $this->assertCount(3, $results);
        $this->assertSame('a.php', $results[0]->file);
        $this->assertSame('b.php', $results[1]->file);
        $this->assertSame('c.php', $results[2]->file);
    }

    public function testInsertMilestone(): void
    {
        $this->backend->insertMilestone('v1.0 shipped', 1719000000, 'First stable release');

        $milestones = $this->backend->milestones();
        $this->assertCount(1, $milestones);
        // milestones() now hydrates Milestone value objects rather than raw arrays.
        $this->assertInstanceOf(Milestone::class, $milestones[0]);
        $this->assertSame('v1.0 shipped', $milestones[0]->name);
        $this->assertSame(1719000000, $milestones[0]->time);
        $this->assertSame('First stable release', $milestones[0]->description);
    }

    public function testMilestonesOrderedByTime(): void
    {
        $this->backend->insertMilestone('later', 1719100000);
        $this->backend->insertMilestone('earlier', 1718900000);
        $this->backend->insertMilestone('middle', 1719000000);

        $milestones = $this->backend->milestones();
        $this->assertCount(3, $milestones);
        $this->assertContainsOnlyInstancesOf(Milestone::class, $milestones);
        $this->assertSame('earlier', $milestones[0]->name);
        $this->assertSame('middle', $milestones[1]->name);
        $this->assertSame('later', $milestones[2]->name);
    }
    public function testLoadRangeHonoursWholeDayBounds(): void
    {
        $day = new \DateTimeImmutable('2026-05-05');
        $inside  = new Heartbeat($day->setTime(9, 30)->getTimestamp(), 'in', 'php', 'a.php', 60);
        $outside = new Heartbeat($day->modify('-2 days')->setTime(9, 30)->getTimestamp(), 'out', 'php', 'b.php', 60);
        $this->backend->insert($inside);
        $this->backend->insert($outside);

        $rows = $this->backend->loadRange($day, $day);
        $this->assertCount(1, $rows);
        $this->assertSame('in', $rows[0]->project);
    }

    public function testInvalidateIsCallableNoOp(): void
    {
        $this->backend->insert(new Heartbeat(time(), 'p', 'php', 'a.php', 60));
        $this->backend->invalidate();
        $this->assertCount(1, $this->backend->loadRange(
            new \DateTimeImmutable('today'), new \DateTimeImmutable('today'),
        ));
    }

    public function testDatabaseFileIsCreatedOwnerPrivate(): void
    {
        clearstatcache();
        $this->assertSame(0o600, fileperms($this->dbPath) & 0o777);
        $this->assertInstanceOf(HeartbeatReader::class, $this->backend);
    }

    /**
     * Concurrent-writer review (senior m2): several connections legitimately
     * share this file (editor plugin + CLI + cron). SQLite's default busy
     * timeout is zero, so before busyTimeout(5000) was armed in the ctor a
     * writer overlapping another transaction failed instantly with
     * "database is locked" instead of waiting the lock out.
     */
    public function testAWriterWaitsOutAConcurrentTransactionInsteadOfFailing(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork is required to hold a lock concurrently');
        }

        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->fail('pcntl_fork failed');
        }
        if ($pid === 0) {
            // Child: take an exclusive lock, hold it ~0.4s, commit, exit.
            // A forked child must never run PHPUnit's shutdown path.
            try {
                $holder = new \SQLite3($this->dbPath);
                $holder->exec('BEGIN EXCLUSIVE');
                $holder->exec("INSERT INTO heartbeats (time, project, language, file, duration, tags) VALUES (1718900002, 'holder', 'php', 'h.php', 60, '[]')");
                usleep(400000);
                $holder->exec('COMMIT');
            } catch (\Throwable) {
                exit(2);
            }
            exit(0);
        }

        usleep(100000); // let the child settle onto its lock
        $status = 0;
        try {
            $this->backend->insert(new Heartbeat(1718900001, 'waiter', 'php', 'w.php', 60));
        } catch (\Throwable $e) {
            pcntl_waitpid($pid, $status);
            $this->fail('a concurrent writer must wait out the lock, got: ' . $e->getMessage());
        }

        pcntl_waitpid($pid, $status);
        $this->assertSame(0, $status, 'the lock-holder child must exit cleanly');

        $projects = array_map(
            static fn(Heartbeat $b): string => $b->project,
            $this->backend->query(1718900000, 1718900010),
        );
        sort($projects);
        $this->assertSame(['holder', 'waiter'], $projects);
    }

    /**
     * The ctor's pragma posture must be live on the connection it hands out.
     * synchronous and cache_size are per-connection settings, so they are
     * read back through the backend's own handle — a second connection would
     * answer with SQLite's defaults, not the ctor's arms (journal_mode=WAL
     * is persistent in the file header, so it would survive either way).
     */
    public function testTheConstructorArmsThePragmaPostureOnItsOwnConnection(): void
    {
        $db = (new \ReflectionProperty(SqliteBackend::class, 'db'))->getValue($this->backend);
        $this->assertInstanceOf(\SQLite3::class, $db);
        $this->assertSame('wal', strtolower((string) $db->querySingle('PRAGMA journal_mode')));
        // SQLITE_BRIEF-free numbering: 1 == NORMAL, 2 == FULL, 3 == EXTRA.
        $this->assertSame(1, (int) $db->querySingle('PRAGMA synchronous'));
        // Spelled back exactly: negative cache_size is kibibytes (62.5 MiB).
        $this->assertSame(-64000, (int) $db->querySingle('PRAGMA cache_size'));
    }

    /**
     * Privacy sweep over the WAL sidecars: a -wal file left loose by an
     * earlier build (or an umask that let SQLite create it 0644) is
     * tightened by the next open, like the main database already was.
     */
    public function testALooseWalSidecarIsTightenedByTheNextOpen(): void
    {
        $this->backend->insert(new Heartbeat(time: 1718900000, project: 'p', language: 'php', file: 'a.php'));
        $sidecar = $this->dbPath . '-wal';
        // The first write in WAL mode materialises the sidecar while this
        // connection stays open — fail loud, never skip, if that changes.
        $this->assertFileExists($sidecar);
        $this->assertTrue(chmod($sidecar, 0o644));
        clearstatcache(true, $sidecar);
        $this->assertSame(0o644, fileperms($sidecar) & 0o777);

        new SqliteBackend($this->dbPath); // second open runs the sweep

        clearstatcache(true, $sidecar);
        $this->assertSame(0o600, fileperms($sidecar) & 0o777);
    }

    /**
     * The synchronous=NORMAL arm is conditional, never a straight exec: a
     * refused WAL migration (read-only filesystem, locking-protocol clash)
     * still answers exec() with the OLD mode, and NORMAL on a rollback
     * journal is the corruption-prone combination. Forcing a refusal
     * portably would need a read-only mount, so this pins the guard's SHAPE
     * on the constructor source — journal_mode is read back after the WAL
     * exec, and the synchronous exec sits inside a conditional keyed to
     * 'wal' that follows the read-back. The live posture on the normal
     * path is pinned by testTheConstructorArmsThePragmaPostureOnItsOwnConnection;
     * an inverted guard would redden that one (FULL on a confirmed WAL file),
     * so polarity is covered without faking a refusal.
     */
    public function testTheSynchronousArmIsGatedBehindTheJournalModeReadBack(): void
    {
        $source = (string) file_get_contents(
            (new \ReflectionMethod(SqliteBackend::class, '__construct'))->getFileName(),
        );
        $setWal = strpos($source, "'PRAGMA journal_mode = WAL'");
        // Quote-terminated needle: cannot match the exec literal above.
        $readBack = strpos($source, "'PRAGMA journal_mode'");
        $synchronous = strpos($source, "'PRAGMA synchronous = NORMAL'");

        $this->assertIsInt($setWal);
        $this->assertIsInt($readBack, 'the ctor must read journal_mode back after the WAL exec');
        $this->assertIsInt($synchronous);
        $this->assertLessThan($readBack, $setWal, 'the read-back must follow the WAL exec');
        $this->assertLessThan($synchronous, $readBack, 'the synchronous arm must follow the read-back');

        $window = substr($source, $setWal, $synchronous - $setWal);
        $this->assertMatchesRegularExpression(
            '/if\s*\(.*\'PRAGMA journal_mode\'.*\'wal\'\s*\)\s*\{/s',
            $window,
            'PRAGMA synchronous must be armed behind a conditional that reads journal_mode back and compares to wal, never unconditionally',
        );
    }

    /**
     * CT-S22 (finding 023): the durable schema must carry a declared
     * version, or the first future column change silently no-ops against
     * every existing database and bricks all writes at the first prepare().
     */
    public function testAFreshDatabaseCarriesTheSchemaVersionStamp(): void
    {
        $raw = new \SQLite3($this->dbPath);
        $this->assertSame(1, (int) $raw->querySingle('PRAGMA user_version'));
        $raw->close();
    }

    /**
     * Every database shipped before versioning IS the v1 layout, so a
     * version-0 file is established idempotently, stamped once, and stays
     * readable and writable through the stamping open.
     */
    public function testAPreVersioningDatabaseIsEstablishedStampedAndStillWorks(): void
    {
        $legacy = $this->dbPath . '-legacy';
        $raw = new \SQLite3($legacy);
        $q = chr(39);
        $raw->exec('CREATE TABLE heartbeats (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            time INTEGER NOT NULL,
            project TEXT NOT NULL,
            language TEXT NOT NULL,
            file TEXT NOT NULL,
            duration INTEGER NOT NULL DEFAULT 60,
            tags TEXT NOT NULL DEFAULT ' . $q . '[]' . $q . '
        )');
        $raw->exec('INSERT INTO heartbeats (time, project, language, file, duration, tags)
            VALUES (1717747200, ' . $q . 'old-proj' . $q . ', ' . $q . 'php' . $q . ', ' . $q . '/x/y.php' . $q . ', 60, ' . $q . '[]' . $q . ')');
        $raw->close();

        $backend = new SqliteBackend($legacy);
        $rows = $backend->query(1717747100, 1717747300);
        $this->assertCount(1, $rows);
        $this->assertSame('old-proj', $rows[0]->project, 'the pre-versioning row survives the stamping open');

        $backend->insert(new Heartbeat(
            time: 1717747260,
            project: 'new-proj',
            language: 'go',
            file: '/x/z.go',
            duration: 30,
        ));
        $this->assertCount(2, $backend->query(1717747100, 1717747400));

        $check = new \SQLite3($legacy);
        $this->assertSame(1, (int) $check->querySingle('PRAGMA user_version'), 'stamped exactly once');
        $this->assertNotFalse($check->querySingle('SELECT id FROM milestones LIMIT 1') === null, 'the missing milestones table was established');
        $check->close();
        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($legacy . $suffix);
        }
    }

    /**
     * The refusal half: a file claiming a version this build does not know
     * is shut out with one owned line naming the number — no silent no-op,
     * no half-migration, no raw PHP warning stack.
     */
    public function testAnUnknownSchemaVersionIsRefusedLoudly(): void
    {
        $raw = new \SQLite3($this->dbPath);
        $raw->exec('PRAGMA user_version = 99');
        $raw->close();

        try {
            new SqliteBackend($this->dbPath);
            $this->fail('a database from a future schema must not open silently');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('schema version 99', $error->getMessage());
            $this->assertStringContainsString('does not understand', $error->getMessage());
        }
    }
}

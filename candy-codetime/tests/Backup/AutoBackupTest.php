<?php

declare(strict_types=1);

namespace SugarCraft\CodeTime\Tests\Backup;

use PHPUnit\Framework\TestCase;
use SugarCraft\CodeTime\Backup\AutoBackup;

final class AutoBackupTest extends TestCase
{
    private string $backupDir;
    private string $dataDir;

    protected function setUp(): void
    {
        $this->backupDir = sys_get_temp_dir() . '/candy-codetime-backup-' . uniqid();
        $this->dataDir = sys_get_temp_dir() . '/candy-codetime-data-' . uniqid();
        mkdir($this->dataDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $backupFiles = array_merge(glob($this->backupDir . '/*') ?: [], glob($this->backupDir . '/.*') ?: []);
        foreach ($backupFiles as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        if (is_dir($this->backupDir)) {
            @rmdir($this->backupDir);
        }
        $dataFiles = glob($this->dataDir . '/*');
        foreach ($dataFiles as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        if (is_dir($this->dataDir)) {
            @rmdir($this->dataDir);
        }
    }

    /**
     * CT-S23 (finding 024): the sqlite retention door. VACUUM INTO must
     * produce a restorable, owner-private snapshot; the live db stays
     * untouched and no sidecar travels with the archive.
     */
    public function testArchiveSqliteDatabaseProducesARestorablePrivateSnapshot(): void
    {
        if (!extension_loaded('sqlite3')) {
            $this->markTestSkipped('sqlite3 extension not loaded');
        }
        $dbPath = $this->dataDir . '/candy-codetime.db';
        $backend = new \SugarCraft\CodeTime\Storage\SqliteBackend($dbPath);
        $beat = static fn(int $t): \SugarCraft\CodeTime\Heartbeat => new \SugarCraft\CodeTime\Heartbeat(
            time: $t,
            project: 'p',
            language: 'php',
            file: '/x/a.php',
            duration: 60,
        );
        $backend->insert($beat(1717747200));
        $backend->insert($beat(1717747260));
        unset($backend);

        $auto = new AutoBackup($this->backupDir, [$this->dataDir]);
        $dest = $auto->archiveSqliteDatabase($dbPath);

        $this->assertFileExists($dest);
        $check = new \SQLite3($dest);
        $this->assertSame(2, (int) $check->querySingle('SELECT count(*) FROM heartbeats'));
        $this->assertNotFalse($check->querySingle('SELECT count(*) FROM milestones'));
        $check->close();
        clearstatcache();
        $this->assertSame(0o600, fileperms($dest) & 0o777, 'an archived database is the payload: owner-private');
    }

    /**
     * A snapshot of a second, taken a second later, is a NEW file — but a
     * same-second re-run must never clobber what already landed.
     */
    public function testArchiveSqliteDatabaseNeverOverwritesAnExistingSnapshot(): void
    {
        if (!extension_loaded('sqlite3')) {
            $this->markTestSkipped('sqlite3 extension not loaded');
        }
        $dbPath = $this->dataDir . '/candy-codetime.db';
        $backend = new \SugarCraft\CodeTime\Storage\SqliteBackend($dbPath);
        unset($backend);

        $auto = new AutoBackup($this->backupDir, [$this->dataDir]);
        $startedAt = date('Y-m-d-His');
        $auto->archiveSqliteDatabase($dbPath);

        try {
            $auto->archiveSqliteDatabase($dbPath);
            if (date('Y-m-d-His') === $startedAt) {
                $this->fail('a second archive inside the same second must refuse to overwrite');
            }
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('could not archive', $e->getMessage());
        }
    }

    public function testArchiveSqliteDatabaseRefusesAMissingDatabase(): void
    {
        $auto = new AutoBackup($this->backupDir, [$this->dataDir]);
        try {
            $auto->archiveSqliteDatabase($this->dataDir . '/absent.db');
            $this->fail('archiving a database that is not there must refuse, not mint an empty file');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('nothing to archive', $e->getMessage());
        }
        $this->assertDirectoryDoesNotExist($this->backupDir, 'a refused archive leaves no directory behind');
    }

    public function testRotateCopiesOldFiles(): void
    {
        // Create a file with old mtime (40 days ago)
        $file = $this->dataDir . '/2024-01-15.jsonl';
        file_put_contents($file, '{"time":1705334400}');
        touch($file, time() - (40 * 86400));

        $backup = new AutoBackup($this->backupDir, [$this->dataDir]);
        $count = $backup->rotate(30);

        $this->assertSame(1, $count);
        $backups = $backup->listBackups();
        $this->assertCount(1, $backups);
        $this->assertStringContainsString('2024-01-15', $backups[0]);
    }

    public function testRotateSkipsRecentFiles(): void
    {
        // Create a file with recent mtime (today)
        $file = $this->dataDir . '/' . date('Y-m-d') . '.jsonl';
        file_put_contents($file, '{"time":' . time() . '}');

        $backup = new AutoBackup($this->backupDir, [$this->dataDir]);
        $count = $backup->rotate(30);

        $this->assertSame(0, $count);
        $this->assertCount(0, $backup->listBackups());
    }

    public function testRotateDeduplicates(): void
    {
        $file = $this->dataDir . '/old-day.jsonl';
        file_put_contents($file, '{"time":1}');
        touch($file, time() - (40 * 86400));

        $backup = new AutoBackup($this->backupDir, [$this->dataDir]);
        $backup->rotate(30);
        $count = $backup->rotate(30); // Run again

        $this->assertSame(0, $count);
        $this->assertCount(1, $backup->listBackups());
    }

    public function testListBackupsEmpty(): void
    {
        $backup = new AutoBackup($this->backupDir, []);
        $this->assertSame([], $backup->listBackups());
    }

    public function testListBackupsAfterRotate(): void
    {
        $file = $this->dataDir . '/2024-02-01.jsonl';
        file_put_contents($file, '{}');
        touch($file, time() - (35 * 86400));

        $backup = new AutoBackup($this->backupDir, [$this->dataDir]);
        $backup->rotate(30);

        $backups = $backup->listBackups();
        $this->assertCount(1, $backups);
        $this->assertStringContainsString('2024-02-01', $backups[0]);
    }

    public function testRotateCreatesBackupDirOnlyWhenItArchives(): void
    {
        // Nothing to archive must leave nothing behind: the pre-fix shape
        // mkdir'ed the sibling unconditionally (at 0755) on every cron tick.
        $backup = new AutoBackup($this->backupDir, []);
        $this->assertFalse(is_dir($this->backupDir));
        $this->assertSame(0, $backup->rotate());
        $this->assertFalse(is_dir($this->backupDir), 'an empty archive run must not create the backup directory');

        // …and the same call with real work does create it.
        $file = $this->dataDir . '/2024-03-01.jsonl';
        file_put_contents($file, '{"time":1}');
        touch($file, time() - (40 * 86400));
        $this->assertSame(1, (new AutoBackup($this->backupDir, [$this->dataDir]))->rotate());
        $this->assertTrue(is_dir($this->backupDir));
    }

    /**
     * C2/SEC privacy: an archived day file is a byte-for-byte copy of the
     * privacy-sensitive payload, so it must never land at the umask default.
     * Both halves are pinned: the directory is 0700 and each copy is 0600.
     */
    public function testArchivedCopiesAreOwnerPrivateNotWorldReadable(): void
    {
        $file = $this->dataDir . '/2024-04-01.jsonl';
        file_put_contents($file, '{"time":1}');
        chmod($file, 0o600);
        touch($file, time() - (40 * 86400));

        $backup = new AutoBackup($this->backupDir, [$this->dataDir]);
        // Deliberately loose ambient umask: the copy must be tightened after
        // creation, so a wide umask cannot leak the archive.
        $previous = umask(0o022);
        try {
            $this->assertSame(1, $backup->rotate(30));
        } finally {
            umask($previous);
        }

        $this->assertSame(0o700, fileperms($this->backupDir) & 0o777, 'backup dir must be owner-private 0700');
        $copied = $backup->listBackups();
        $this->assertCount(1, $copied);
        $this->assertSame(0o600, fileperms($copied[0]) & 0o777, 'archived copy must be owner-private 0600');
    }

    public function testRotateSkipsNonExistentSourceDir(): void
    {
        $backup = new AutoBackup($this->backupDir, [$this->dataDir . '/nonexistent']);
        $count = $backup->rotate();
        $this->assertSame(0, $count);
    }

    /**
     * SEC/data-loss: a backup dir that cannot be created must throw, not
     * silently return 0. Regression guard for the `@mkdir(...) ... return 0`
     * swallow. Uses a regular file as the parent so mkdir fails even as root.
     * The aged day file is required: creation is now lazy, so a run with
     * nothing to archive would legitimately never touch the path.
     */
    public function testRotateThrowsWhenBackupDirCannotBeCreated(): void
    {
        $blocker = $this->dataDir . '/blocker-file';
        file_put_contents($blocker, 'x');
        $aged = $this->dataDir . '/2024-05-01.jsonl';
        file_put_contents($aged, '{"time":1}');
        touch($aged, time() - (40 * 86400));
        // Nesting under a regular file makes mkdir(recursive) fail regardless of uid.
        $backup = new AutoBackup($blocker . '/nested/backup', [$this->dataDir]);

        // Swallow the low-level mkdir E_WARNING so failOnWarning stays green;
        // the RuntimeException is the contract under test.
        set_error_handler(static fn (): bool => true);
        try {
            $this->expectException(\RuntimeException::class);
            $backup->rotate(30);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * SEC/data-loss: a failed copy of an aged file must throw, not silently
     * skip. Regression guard for the `@copy(...)` swallow. A pre-existing
     * directory at the exact destination path makes copy() fail even as root.
     */
    public function testRotateThrowsWhenCopyFails(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root bypasses file permission modes');
        }
        $mtime = time() - (40 * 86400);
        $file = $this->dataDir . '/2024-01-15.jsonl';
        file_put_contents($file, '{"time":1}');
        chmod($file, 0o000);
        touch($file, $mtime);

        $backup = new AutoBackup($this->backupDir, [$this->dataDir]);

        set_error_handler(static fn (): bool => true);
        try {
            $this->expectException(\RuntimeException::class);
            $backup->rotate(30);
        } finally {
            restore_error_handler();
            chmod($file, 0o644);
        }
    }

    /**
     * CT-S24: copies land through a temp file plus rename. Something standing
     * at the canonical destination (the planted directory the pre-CT-S24
     * naming trick used) must make the run FAIL LOUDLY, not sneak a partial
     * archive past under a mangled name.
     */
    public function testRotateFailsLoudlyWhenSomethingStandsAtTheDestination(): void
    {
        $mtime = time() - (40 * 86400);
        $file = $this->dataDir . '/2024-06-15.jsonl';
        file_put_contents($file, '{"time":1}');
        touch($file, $mtime);

        $dest = $this->backupDir . '/' . basename($this->dataDir) . '_'
            . substr(hash('sha256', realpath($this->dataDir) ?: $this->dataDir), 0, 8)
            . '_2024-06-15.jsonl';
        mkdir($this->backupDir, 0o700, true);
        mkdir($dest, 0o700);

        $backup = new AutoBackup($this->backupDir, [$this->dataDir]);

        set_error_handler(static fn (): bool => true);
        try {
            try {
                $backup->rotate(30);
                $this->fail('a destination blocked by a planted directory must refuse, not half-archive');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('could not move the archived copy into place', $e->getMessage());
            }
        } finally {
            restore_error_handler();
            @rmdir($dest);
        }
    }

    /**
     * CT-S24 (finding 025): the destination keys on the logical day, so a
     * backfilled day file REFRESHES its existing archive instead of minting
     * diverging copies of the same day.
     */
    public function testABackfillRefreshesTheSameCanonicalDestination(): void
    {
        $file = $this->dataDir . '/2024-07-01.jsonl';
        $row = json_encode(['time' => 1719814800, 'project' => 'p', 'language' => 'php', 'file' => '/x/a.php', 'duration' => 60, 'tags' => []]) . "\n";
        file_put_contents($file, $row);
        $aged = time() - (90 * 86400);
        touch($file, $aged);

        $backup = new AutoBackup($this->backupDir, [$this->dataDir]);
        $this->assertSame(1, $backup->rotate(30));
        $first = $backup->listBackups();
        $this->assertCount(1, $first);

        // Backfill adds a row, the file ages again, rotate runs once more:
        // same destination name, grown to the source's size.
        file_put_contents($file, $row, FILE_APPEND | LOCK_EX);
        touch($file, $aged);
        $this->assertSame(1, $backup->rotate(30));
        $second = $backup->listBackups();
        $this->assertCount(1, $second, 'one day owns exactly one canonical archive');
        $this->assertSame($first[0], $second[0]);
        $this->assertSame(2, substr_count((string) file_get_contents($second[0]), "\n"));
        $this->assertSame(filesize($file), filesize($second[0]));
    }

    /**
     * CT-S24 (finding 050): two same-basename roots archive side by side —
     * the path digest in the slug keeps each root on its own destination.
     */
    public function testTwoSameBasenameRootsArchiveSideBySide(): void
    {
        $alpha = $this->dataDir . '/alpha/proj';
        $beta = $this->dataDir . '/beta/proj';
        mkdir($alpha, 0o700, true);
        mkdir($beta, 0o700, true);
        $aged = time() - (40 * 86400);
        file_put_contents($alpha . '/2024-08-01.jsonl', '{"time":1}' . "\n");
        file_put_contents($beta . '/2024-08-01.jsonl', '{"time":2}' . "\n\n");
        touch($alpha . '/2024-08-01.jsonl', $aged);
        touch($beta . '/2024-08-01.jsonl', $aged);

        $backup = new AutoBackup($this->backupDir, [$alpha, $beta]);
        try {
            $this->assertSame(2, $backup->rotate(30));
            $list = $backup->listBackups();
            $this->assertCount(2, $list);
            $this->assertNotSame(basename($list[0]), basename($list[1]));
            $sizes = [filesize($list[0]), filesize($list[1])];
            sort($sizes);
            $this->assertSame([11, 12], $sizes, 'each root archives its own bytes, neither shadows the other');
        } finally {
            @unlink($alpha . '/2024-08-01.jsonl');
            @unlink($beta . '/2024-08-01.jsonl');
            @rmdir($alpha);
            @rmdir($beta);
            @rmdir($this->dataDir . '/alpha');
            @rmdir($this->dataDir . '/beta');
        }
    }

    /**
     * CT-S24 (finding 089): an overlapping run refuses instead of queueing
     * half-copies into the same destination.
     */
    public function testRotateRefusesToOverlapAConcurrentRun(): void
    {
        $file = $this->dataDir . '/2024-09-01.jsonl';
        file_put_contents($file, '{"time":1}');
        touch($file, time() - (40 * 86400));

        mkdir($this->backupDir, 0o700, true);
        $lock = fopen($this->backupDir . '/.lock', 'c');
        $this->assertNotFalse($lock);
        $this->assertNotFalse(flock($lock, LOCK_EX | LOCK_NB));
        $backup = new AutoBackup($this->backupDir, [$this->dataDir]);
        try {
            try {
                $backup->rotate(30);
                $this->fail('an overlapping run must refuse, not queue behind the lock');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('already writing', $e->getMessage());
            }
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function testRotateSkipsVanishedFile(): void
    {
        // Create a file then remove it before rotate processes it
        $file = $this->dataDir . '/2024-01-15.jsonl';
        file_put_contents($file, '{"time":1705334400}');
        touch($file, time() - (40 * 86400));

        // Remove the file before rotate runs (simulates race condition)
        unlink($file);

        $backup = new AutoBackup($this->backupDir, [$this->dataDir]);
        $count = $backup->rotate(30);

        // Should not throw or produce 1970-dated backup - just skip the vanished file
        $this->assertSame(0, $count);
        $backups = $backup->listBackups();
        $this->assertCount(0, $backups);
    }
}

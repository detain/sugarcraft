<?php

declare(strict_types=1);

namespace SugarCraft\Tick\Tests\Backup;

use PHPUnit\Framework\TestCase;
use SugarCraft\Tick\Backup\AutoBackup;

final class AutoBackupTest extends TestCase
{
    private string $backupDir;
    private string $dataDir;

    protected function setUp(): void
    {
        $this->backupDir = sys_get_temp_dir() . '/sugar-tick-backup-' . uniqid();
        $this->dataDir = sys_get_temp_dir() . '/sugar-tick-data-' . uniqid();
        mkdir($this->dataDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $backupFiles = glob($this->backupDir . '/*');
        foreach ($backupFiles as $f) {
            if (is_file($f)) {
                unlink($f);
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

    public function testRotateCreatesBackupDir(): void
    {
        $backup = new AutoBackup($this->backupDir, []);
        $this->assertFalse(is_dir($this->backupDir));
        $backup->rotate();
        $this->assertTrue(is_dir($this->backupDir));
    }

    public function testRotateSkipsNonExistentSourceDir(): void
    {
        $backup = new AutoBackup($this->backupDir, [$this->dataDir . '/nonexistent']);
        $count = $backup->rotate();
        $this->assertSame(0, $count);
    }
}

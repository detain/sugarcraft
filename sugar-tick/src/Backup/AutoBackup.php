<?php

declare(strict_types=1);

namespace SugarCraft\Tick\Backup;

/**
 * Rotating backup manager for JSONL data files.
 * Archives (copies) files whose mtime has aged past the keepDays threshold.
 */
final class AutoBackup
{
    /** @param list<string> $dirs directories to back up */
    public function __construct(
        private readonly string $backupDir,
        private readonly array $dirs = [],
    ) {}

    /**
     * Rotate backups: archive files older than keepDays.
     *
     * @param int $keepDays number of days to keep
     * @return int number of files copied
     *
     * @throws \RuntimeException if the backup directory cannot be created or a
     *                          file copy fails — a backup must never fail silently
     *                          (silent failure = undetected data loss).
     */
    public function rotate(int $keepDays = 30): int
    {
        $count = 0;
        $cutoff = time() - ($keepDays * 86400);

        // Surface a failed mkdir instead of swallowing it: a backup run that
        // cannot create its target directory has lost data, and the caller
        // must be told rather than seeing a silent "0 files copied".
        if (!is_dir($this->backupDir) && !mkdir($this->backupDir, 0755, true) && !is_dir($this->backupDir)) {
            throw new \RuntimeException("sugar-tick: failed to create backup directory: {$this->backupDir}");
        }

        foreach ($this->dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $files = glob($dir . '/*.jsonl') ?: [];
            foreach ($files as $file) {
                $mtime = @filemtime($file);
                if ($mtime === false) {
                    continue;
                }
                if ($mtime < $cutoff) {
                    $dest = $this->backupDir . '/' . basename($dir) . '_' . basename($file) . '.' . date('Y-m-d', $mtime);
                    if (!is_file($dest)) {
                        // Throw on a failed copy rather than silently skipping it:
                        // a swallowed copy failure means an aged data file is being
                        // rotated out with no archived copy — data loss.
                        if (!copy($file, $dest)) {
                            throw new \RuntimeException("sugar-tick: failed to copy {$file} to {$dest}");
                        }
                        ++$count;
                    }
                }
            }
        }

        return $count;
    }

    /**
     * @return list<string> list of backup files
     */
    public function listBackups(): array
    {
        if (!is_dir($this->backupDir)) {
            return [];
        }
        return glob($this->backupDir . '/*') ?: [];
    }
}

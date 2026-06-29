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
     */
    public function rotate(int $keepDays = 30): int
    {
        $count = 0;
        $cutoff = time() - ($keepDays * 86400);

        if (!is_dir($this->backupDir) && !@mkdir($this->backupDir, 0755, true) && !is_dir($this->backupDir)) {
            return 0;
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
                        if (@copy($file, $dest)) {
                            ++$count;
                        }
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

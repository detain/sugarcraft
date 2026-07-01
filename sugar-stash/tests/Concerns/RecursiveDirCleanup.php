<?php

declare(strict_types=1);

namespace SugarCraft\Stash\Tests\Concerns;

/**
 * Provides recursive directory cleanup for tests that create temp directories.
 */
trait RecursiveDirCleanup
{
    /**
     * Recursively remove a directory and all its contents.
     */
    protected function removeDir(string $dir): void
    {
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
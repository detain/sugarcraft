<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Skills;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Provides a recursive directory removal helper for test cleanup.
 */
trait TemporaryDirectoryTrait
{
    /**
     * Recursively remove a directory and all its contents.
     */
    protected function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($dir);
    }
}

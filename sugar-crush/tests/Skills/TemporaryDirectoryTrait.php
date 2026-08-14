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
            // A symlink TO a directory answers true to isDir() and false to
            // rmdir(); tests that plant one (symlinked skill dirs) would leave
            // the tree behind and warn. isLink() is checked first.
            if ($item->isLink() || !$item->isDir()) {
                unlink($item->getPathname());
            } else {
                rmdir($item->getPathname());
            }
        }
        rmdir($dir);
    }
}

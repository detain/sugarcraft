<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Tests\Concerns;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Provides temporary directory setup and cleanup for tests.
 *
 * Use this trait in test classes that need isolated temp directories.
 * Each test class should declare a private $tmpDir property that this
 * trait will manage.
 *
 * @mixin TestCase
 */
trait TemporaryDirectoryTrait
{
    /** @var string */
    private string $tmpDir = '';

    /**
     * Override to return a unique suffix for the temp directory name.
     * E.g. 'assertions', 'tape', 'golden'.
     */
    protected function getTempDirSuffix(): string
    {
        return 'test';
    }

    /**
     * Override to perform additional setup after temp dir creation.
     * Call parent::setUp() first when extending.
     */
    protected function setUpTemporaryDirectory(): void
    {
        $suffix = $this->getTempDirSuffix();
        $this->tmpDir = sys_get_temp_dir() . '/candy-testing-' . $suffix . '-' . getmypid();
        mkdir($this->tmpDir, 0755, true);
    }

    /**
     * Override to perform additional cleanup before temp dir removal.
     * Call parent::tearDown() last when extending.
     */
    protected function tearDownTemporaryDirectory(): void
    {
        $dir = $this->tmpDir;
        $this->tmpDir = '';
        if ($dir !== '' && is_dir($dir)) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $file) {
                if ($file->isDir()) {
                    rmdir($file->getPathname());
                } else {
                    unlink($file->getPathname());
                }
            }
            rmdir($dir);
        }
    }
}

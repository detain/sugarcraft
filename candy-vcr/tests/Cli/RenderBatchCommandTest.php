<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cli\RenderBatchCommand;

/**
 * Tests for RenderBatchCommand CLI.
 * Covers collectTapeFiles() method.
 */
final class RenderBatchCommandTest extends TestCase
{
    private function invokeCollectTapeFiles(string $dir, bool $recursive): array
    {
        $ref = new \ReflectionMethod(\SugarCraft\Vcr\Cli\RenderBatchCommand::class, 'collectTapeFiles');
        $ref->setAccessible(true);
        $cmd = new \SugarCraft\Vcr\Cli\RenderBatchCommand();
        return $ref->invoke($cmd, $dir, $recursive);
    }

    public function testCollectTapeFilesNonRecursive(): void
    {
        $dir = sys_get_temp_dir() . '/cv-batch-test-' . uniqid();
        mkdir($dir);

        // Create some .tape files
        file_put_contents($dir . '/a.tape', 'Set Theme "TokyoNight"');
        file_put_contents($dir . '/b.tape', 'Type "hello"');
        file_put_contents($dir . '/c.txt', 'not a tape');
        file_put_contents($dir . '/d.TAPE', 'also not');

        try {
            $files = $this->invokeCollectTapeFiles($dir, false);

            // Only lowercase .tape files should be collected
            $this->assertCount(2, $files);
            $basenames = array_map('basename', $files);
            $this->assertContains('a.tape', $basenames);
            $this->assertContains('b.tape', $basenames);
        } finally {
            unlink($dir . '/a.tape');
            unlink($dir . '/b.tape');
            unlink($dir . '/c.txt');
            unlink($dir . '/d.TAPE');
            rmdir($dir);
        }
    }

    public function testCollectTapeFilesRecursive(): void
    {
        $dir = sys_get_temp_dir() . '/cv-batch-test-' . uniqid();
        mkdir($dir);
        mkdir($dir . '/subdir');
        mkdir($dir . '/subdir/deep');

        file_put_contents($dir . '/root.tape', 'Set Theme "TokyoNight"');
        file_put_contents($dir . '/subdir/nested.tape', 'Type "hello"');
        file_put_contents($dir . '/subdir/deep/file.tape', 'Enter');

        try {
            $files = $this->invokeCollectTapeFiles($dir, true);

            $this->assertCount(3, $files);
            $basenames = array_map('basename', $files);
            $this->assertContains('root.tape', $basenames);
            $this->assertContains('nested.tape', $basenames);
            $this->assertContains('file.tape', $basenames);
        } finally {
            unlink($dir . '/root.tape');
            unlink($dir . '/subdir/nested.tape');
            unlink($dir . '/subdir/deep/file.tape');
            rmdir($dir . '/subdir/deep');
            rmdir($dir . '/subdir');
            rmdir($dir);
        }
    }

    public function testCollectTapeFilesIgnoresNonFiles(): void
    {
        $dir = sys_get_temp_dir() . '/cv-batch-test-' . uniqid();
        mkdir($dir);

        // Create a directory that looks like a tape file
        mkdir($dir . '/fake.tape');

        try {
            $files = $this->invokeCollectTapeFiles($dir, false);

            $this->assertCount(0, $files);
        } finally {
            rmdir($dir . '/fake.tape');
            rmdir($dir);
        }
    }
}

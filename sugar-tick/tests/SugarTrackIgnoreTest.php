<?php

declare(strict_types=1);

namespace SugarCraft\Tick\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Tick\Ignore\SugarTrackIgnore;

final class SugarTrackIgnoreTest extends TestCase
{
    private string $tmpDir;
    private string $tmpFile;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/sugar-tick-ignore-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        $this->tmpFile = $this->tmpDir . '/.sugartrackignore';
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            $files = scandir($this->tmpDir);
            foreach ($files as $file) {
                if ($file !== '.' && $file !== '..') {
                    unlink($this->tmpDir . '/' . $file);
                }
            }
            rmdir($this->tmpDir);
        }
    }

    public function testLoadReturnsEmptyForMissingFile(): void
    {
        $ignore = SugarTrackIgnore::load('/nonexistent/path/.sugartrackignore');
        $this->assertFalse($ignore->isIgnored('any/file.php'));
    }

    public function testLoadIgnoresComments(): void
    {
        file_put_contents($this->tmpFile, "# This is a comment\n*.log\n");
        $ignore = SugarTrackIgnore::load($this->tmpFile);
        $this->assertFalse($ignore->isIgnored('README.md'));
        $this->assertTrue($ignore->isIgnored('debug.log'));
    }

    public function testLoadIgnoresEmptyLines(): void
    {
        file_put_contents($this->tmpFile, "\n\n*.log\n\n");
        $ignore = SugarTrackIgnore::load($this->tmpFile);
        $this->assertTrue($ignore->isIgnored('debug.log'));
    }

    public function testIsIgnoredByBasename(): void
    {
        file_put_contents($this->tmpFile, "*.log\n");
        $ignore = SugarTrackIgnore::load($this->tmpFile);
        $this->assertTrue($ignore->isIgnored('debug.log'));
        $this->assertFalse($ignore->isIgnored('debug.txt'));
    }

    public function testIsIgnoredByFullPath(): void
    {
        file_put_contents($this->tmpFile, "vendor/*\n");
        $ignore = SugarTrackIgnore::load($this->tmpFile);
        $this->assertTrue($ignore->isIgnored('vendor/autoload.php'));
        $this->assertFalse($ignore->isIgnored('src/main.php'));
    }

    public function testIsIgnoredByFullPathPrefix(): void
    {
        file_put_contents($this->tmpFile, "/full/path/to/file\n");
        $ignore = SugarTrackIgnore::load($this->tmpFile);
        $this->assertTrue($ignore->isIgnored('/full/path/to/file'));
        $this->assertFalse($ignore->isIgnored('/other/path/to/file'));
    }

    public function testIsIgnoredMultiplePatterns(): void
    {
        file_put_contents($this->tmpFile, "*.log\nvendor/*\n*.tmp\n");
        $ignore = SugarTrackIgnore::load($this->tmpFile);
        $this->assertTrue($ignore->isIgnored('debug.log'));
        $this->assertTrue($ignore->isIgnored('vendor/autoload.php'));
        $this->assertTrue($ignore->isIgnored('cache.tmp'));
        $this->assertFalse($ignore->isIgnored('main.php'));
    }
}

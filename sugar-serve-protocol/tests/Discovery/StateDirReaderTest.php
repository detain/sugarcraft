<?php

declare(strict_types=1);

namespace SugarCraft\ServeProtocol\Tests\Discovery;

use PHPUnit\Framework\TestCase;
use SugarCraft\ServeProtocol\Discovery\StateDirReader;
use SugarCraft\ServeProtocol\Tests\Support\TempTree;

final class StateDirReaderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = TempTree::make();
    }

    protected function tearDown(): void
    {
        TempTree::remove($this->root);
    }

    public function testMissingDirectoryIsNoServer(): void
    {
        self::assertNull(StateDirReader::existing($this->root . '/absent'));
    }

    public function testPrivateDirectoryReturnsItsPath(): void
    {
        $dir = $this->root . '/state';
        \mkdir($dir, 0o700);
        \chmod($dir, 0o700);

        self::assertSame($dir, StateDirReader::existing($dir));
    }

    public function testTrailingSlashIsTrimmed(): void
    {
        $dir = $this->root . '/state';
        \mkdir($dir, 0o700);
        \chmod($dir, 0o700);

        self::assertSame($dir, StateDirReader::existing($dir . '/'));
    }

    public function testWorldReadableDirectoryThrows(): void
    {
        $dir = $this->root . '/state';
        \mkdir($dir, 0o755);
        \chmod($dir, 0o755);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is mode 0755, not 0700');
        StateDirReader::existing($dir);
    }

    public function testSymlinkThrows(): void
    {
        $target = $this->root . '/real';
        \mkdir($target, 0o700);
        \chmod($target, 0o700);
        $link = $this->root . '/link';
        \symlink($target, $link);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is a symbolic link');
        StateDirReader::existing($link);
    }
}

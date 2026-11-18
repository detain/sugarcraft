<?php

declare(strict_types=1);

namespace SugarCraft\ServeProtocol\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\ServeProtocol\Support\PrivateDirCheck;

final class PrivateDirCheckTest extends TestCase
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

    public function testPrivateDirectoryOwnedByUserPasses(): void
    {
        $dir = $this->root . '/state';
        \mkdir($dir, 0o700);
        \chmod($dir, 0o700);

        self::assertNull(PrivateDirCheck::refusal($dir, 'test'));
    }

    public function testWorldReadableDirectoryIsRefused(): void
    {
        $dir = $this->root . '/state';
        \mkdir($dir, 0o755);
        \chmod($dir, 0o755);

        self::assertStringContainsString('is mode 0755, not 0700', (string) PrivateDirCheck::refusal($dir, 'test'));
    }

    public function testDirectoryOwnedByAnotherUidIsRefused(): void
    {
        $dir = $this->root . '/state';
        \mkdir($dir, 0o700);
        \chmod($dir, 0o700);
        $own = (int) \fileowner($dir);

        self::assertStringContainsString('owned by uid', (string) PrivateDirCheck::refusal($dir, 'test', $own + 1));
    }

    public function testSymlinkIsRefused(): void
    {
        $target = $this->root . '/real';
        \mkdir($target, 0o700);
        \chmod($target, 0o700);
        $link = $this->root . '/link';
        \symlink($target, $link);

        self::assertStringContainsString('is a symbolic link', (string) PrivateDirCheck::refusal($link, 'test'));
    }

    public function testRegularFileIsRefused(): void
    {
        $file = $this->root . '/file';
        \file_put_contents($file, 'x');

        self::assertStringContainsString('is not a directory', (string) PrivateDirCheck::refusal($file, 'test'));
    }

    public function testMissingPathIsRefused(): void
    {
        self::assertStringContainsString('does not exist', (string) PrivateDirCheck::refusal($this->root . '/absent', 'test'));
    }
}

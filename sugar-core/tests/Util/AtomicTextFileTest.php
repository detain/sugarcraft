<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\AtomicTextFile;

final class AtomicTextFileTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/sugar-core-atf-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f) || is_link($f)) {
                @unlink($f);
            }
        }
        @rmdir($this->dir);
    }

    public function testWriteCreatesParentAndPublishesExactBytes(): void
    {
        $f = AtomicTextFile::at($this->dir . '/a.md');
        $f->write("héllo\n\0bin");
        self::assertSame("héllo\n\0bin", file_get_contents($this->dir . '/a.md'));
        self::assertSame(0700, fileperms($this->dir) & 0777);
    }

    public function testWriteReplacesAndLeavesNoTemp(): void
    {
        $f = AtomicTextFile::at($this->dir . '/a.md');
        $f->write('one');
        $f->write('two');
        self::assertSame('two', file_get_contents($this->dir . '/a.md'));
        self::assertSame(['a.md'], array_values(array_diff(scandir($this->dir), ['.', '..'])));
    }

    public function testPermissionsAppliedAndTightenAnExistingLooseFile(): void
    {
        mkdir($this->dir, 0700);
        file_put_contents($this->dir . '/s', 'old');
        chmod($this->dir . '/s', 0644);
        AtomicTextFile::at($this->dir . '/s')->withPermissions(0600)->write('secret');
        clearstatcache();
        self::assertSame(0600, fileperms($this->dir . '/s') & 0777);
    }

    public function testWithPermissionsIsImmutableAndAccessorsReport(): void
    {
        $a = AtomicTextFile::at('/x/y');
        $b = $a->withPermissions(0640);
        self::assertNull($a->permissions());
        self::assertSame(0640, $b->permissions());
        self::assertSame('/x/y', $b->path());
    }

    public function testRejectsModeAbovePermissionBits(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AtomicTextFile::at('/x')->withPermissions(04755);
    }

    public function testFailureLeavesOriginalAndNoTemp(): void
    {
        mkdir($this->dir, 0700);
        // Target is a non-empty directory: rename(file, dir) fails.
        mkdir($this->dir . '/t');
        file_put_contents($this->dir . '/t/keep', 'k');
        try {
            AtomicTextFile::at($this->dir . '/t')->write('x');
            self::fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString($this->dir . '/t', $e->getMessage());
        }
        self::assertSame(['t'], array_values(array_diff(scandir($this->dir), ['.', '..'])), 'no temp left behind');
        self::assertFileExists($this->dir . '/t/keep');
        unlink($this->dir . '/t/keep');
        rmdir($this->dir . '/t');
    }

    public function testStaleOrphanTempIsSweptFreshOneAndLookalikesAreKept(): void
    {
        mkdir($this->dir, 0700);
        $stale = $this->dir . '/.a.md.tmp.' . str_repeat('a', 16);
        $fresh = $this->dir . '/.a.md.tmp.' . str_repeat('b', 16);
        $other = $this->dir . '/.a.md.tmp.bak';
        $unrelated = $this->dir . '/.b.md.tmp.' . str_repeat('c', 16);
        foreach ([$stale, $fresh, $other, $unrelated] as $p) {
            file_put_contents($p, 'x');
        }
        touch($stale, time() - AtomicTextFile::ORPHAN_TEMP_MIN_AGE_SECONDS - 10);
        touch($other, time() - 99999);
        touch($unrelated, time() - 99999);

        AtomicTextFile::at($this->dir . '/a.md')->write('go');

        self::assertFileDoesNotExist($stale);
        self::assertFileExists($fresh);
        self::assertFileExists($other);
        self::assertFileExists($unrelated);
    }

    public function testPublishLawOrderIsPinnedStructurally(): void
    {
        $src = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Util/AtomicTextFile.php');
        $chmod = strpos($src, 'chmod($tmp, $this->fileMode)');
        $payload = strpos($src, 'fwrite($handle, $bytes)');
        $sync = strpos($src, 'fsync($handle)');
        $rename = strpos($src, 'rename($tmp, $this->path)');
        $dirSync = strpos($src, '$this->syncDirectory($dir)');
        foreach ([$chmod, $payload, $sync, $rename, $dirSync] as $p) {
            self::assertIsInt($p);
        }
        self::assertLessThan($payload, $chmod, 'mode before the first payload byte');
        self::assertLessThan($sync, $payload);
        self::assertLessThan($rename, $sync, 'fsync before rename');
        self::assertLessThan($dirSync, $rename, 'directory sync after rename');
        self::assertStringNotContainsString('chmod($this->path', $src);
    }
}

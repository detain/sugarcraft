<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionObject;
use RuntimeException;
use SugarCraft\Testing\Concerns\TemporaryDirectoryTrait;

/**
 * The old recursive wipe FOLLOWED planted symlinks out of the scratch
 * directory — a hostile or accidental link inside $tmpDir turned teardown
 * into a delete of an innocent tree (reproduced pre-fix on this box). These
 * pins lock the link-safe shape: links unlink, the containment jail refuses
 * off-temp paths, and each setup mints a private 0700 directory.
 */
final class TemporaryDirLinkSafetyTest extends TestCase
{
    use TemporaryDirectoryTrait;

    /** @var list<string> extra trees this test created and must remove itself */
    private array $owedCleanup = [];

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
    }

    protected function tearDown(): void
    {
        $this->tearDownTemporaryDirectory();

        foreach ($this->owedCleanup as $path) {
            if (is_link($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                foreach (scandir($path) ?: [] as $entry) {
                    if ($entry !== '.' && $entry !== '..') {
                        $child = $path . '/' . $entry;
                        is_link($child) || is_file($child) ? unlink($child) : rmdir($child);
                    }
                }
                @rmdir($path);
            } elseif (is_file($path)) {
                unlink($path);
            }
        }
        $this->owedCleanup = [];
    }

    public function testTeardownUnlinksPlantedSymlinksWithoutFollowingThem(): void
    {
        $victimDir  = $this->makeOutsideTree('victim-dir');
        $victimFile = $victimDir . '/precious.txt';
        file_put_contents($victimFile, 'do not delete me');

        // A link-to-directory inside the scratch: is_dir() follows it, so an
        // order-blind walker would recurse and delete through it.
        symlink($victimDir, $this->tmpDir . '/escape');
        // A link-to-file, same story for the file branch.
        symlink($victimFile, $this->tmpDir . '/escape-file');

        $scratch = $this->tmpDir;
        $this->tearDownTemporaryDirectory();

        self::assertDirectoryDoesNotExist($scratch, 'the scratch itself must be gone');
        self::assertFileExists($victimFile, 'teardown followed a planted symlink into the victim tree');
        self::assertDirectoryExists($victimDir);
    }

    public function testTeardownRefusesWhenTheScratchPathItselfIsASymlink(): void
    {
        $real = sys_get_temp_dir() . '/sugar-testing-linktarget-' . bin2hex(random_bytes(4));
        mkdir($real, 0700);
        $this->owedCleanup[] = $real;

        $link = sys_get_temp_dir() . '/sugar-testing-linkself-' . bin2hex(random_bytes(4));
        symlink($real, $link);
        $this->owedCleanup[] = $link;

        // Point the recorded scratch at the link (the recorded value, not a
        // second real directory).
        $this->setTmpDir($link);

        try {
            $this->tearDownTemporaryDirectory();
            self::fail('a scratch path that is a symlink must raise, not wipe');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('itself is a symlink', $e->getMessage());
        }

        self::assertDirectoryExists($real, 'the link target must survive the refused wipe');
    }

    public function testTeardownRefusesToWipeOutsideTheSystemTempRoot(): void
    {
        $outside = '/var/tmp/sugar-testing-link-safety-' . bin2hex(random_bytes(4));
        if (@mkdir($outside, 0700) === false) {
            self::markTestSkipped('cannot create ' . $outside . ' on this box');
        }
        $this->owedCleanup[] = $outside;

        $this->setTmpDir($outside);

        try {
            $this->tearDownTemporaryDirectory();
            self::fail('an off-temp scratch path must raise, not be deleted');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('does not resolve inside', $e->getMessage());
        }

        self::assertDirectoryExists($outside);
    }

    public function testTwoSetupsMintDistinctPrivateDirectories(): void
    {
        $first = $this->tmpDir;
        $this->setUpTemporaryDirectory();
        $second = $this->tmpDir;

        self::assertNotSame($first, $second, 'each setup must mint a fresh, collision-free directory');
        self::assertDirectoryExists($first);
        self::assertDirectoryExists($second);

        // The trait's own 0700 law (umask-proof assertion via explicit chmod
        // would hide a regression; assert what mkdir promised on the box's
        // default umask, tolerating only the exact mode).
        self::assertSame('0700', substr(sprintf('%o', fileperms($second)), -4));

        @rmdir($first);
    }

    private function setTmpDir(string $path): void
    {
        $property = (new ReflectionObject($this))->getProperty('tmpDir');
        $property->setAccessible(true);
        $property->setValue($this, $path);
    }

    private function makeOutsideTree(string $label): string
    {
        $path = sys_get_temp_dir() . '/sugar-testing-' . $label . '-' . bin2hex(random_bytes(4));
        mkdir($path, 0700);
        $this->owedCleanup[] = $path;

        return $path;
    }
}

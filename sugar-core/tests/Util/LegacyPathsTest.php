<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\LegacyPaths;

/**
 * Pins for the rename-campaign directory shim (plan_rename.md step 0.5): a
 * pre-rename state dir moves exactly once onto its new path, a pre-existing
 * new path blocks the move (never merge), and a failed move is reported with
 * the OS reason while leaving the old dir in place.
 */
final class LegacyPathsTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . \DIRECTORY_SEPARATOR
            . 'sugar-core-legacypaths-' . bin2hex(random_bytes(8));
        if (mkdir($this->tmpDir, 0700, true) === false && is_dir($this->tmpDir) === false) {
            $this->fail("Could not create temp dir: {$this->tmpDir}");
        }
    }

    protected function tearDown(): void
    {
        $this->deleteRecursively($this->tmpDir);
    }

    public function testMovesOldDirToAbsentNewPathAndReportsTheMove(): void
    {
        $old = $this->tmpDir . '/old-state';
        $new = $this->tmpDir . '/new-state';
        mkdir($old, 0700, true);
        file_put_contents($old . '/config.json', '{"ok":true}');

        $this->assertSame("moved {$old} -> {$new}", LegacyPaths::migrate($old, $new));
        $this->assertDirectoryDoesNotExist($old);
        $this->assertFileExists($new . '/config.json');
        $this->assertSame('{"ok":true}', file_get_contents($new . '/config.json'));
    }

    public function testSecondCallAfterASuccessfulMoveIsANoOp(): void
    {
        $old = $this->tmpDir . '/old-state';
        $new = $this->tmpDir . '/new-state';
        mkdir($old, 0700, true);

        $this->assertNotNull(LegacyPaths::migrate($old, $new));
        $this->assertNull(LegacyPaths::migrate($old, $new));
    }

    public function testExistingNewDirBlocksTheMoveWithoutMerging(): void
    {
        $old = $this->tmpDir . '/old-state';
        $new = $this->tmpDir . '/new-state';
        mkdir($old, 0700, true);
        mkdir($new, 0700, true);
        file_put_contents($old . '/only-old.json', 'a');
        file_put_contents($new . '/only-new.json', 'b');

        $this->assertNull(LegacyPaths::migrate($old, $new));
        $this->assertFileExists($old . '/only-old.json');
        $this->assertFileDoesNotExist($new . '/only-old.json');
    }

    public function testExistingNewFileBlocksTheMove(): void
    {
        $old = $this->tmpDir . '/old-state';
        $new = $this->tmpDir . '/new-file';
        mkdir($old, 0700, true);
        file_put_contents($new, 'occupied');

        $this->assertNull(LegacyPaths::migrate($old, $new));
        $this->assertDirectoryExists($old);
    }

    public function testMissingOldPathIsANoOp(): void
    {
        $this->assertNull(LegacyPaths::migrate($this->tmpDir . '/never-existed', $this->tmpDir . '/new-state'));
    }

    public function testOldPathThatIsAFileIsANoOp(): void
    {
        $old = $this->tmpDir . '/old-file';
        file_put_contents($old, 'not a dir');

        $this->assertNull(LegacyPaths::migrate($old, $this->tmpDir . '/new-state'));
        $this->assertFileExists($old);
    }

    public function testCreatesMissingParentChainWithPrivateMode(): void
    {
        $old = $this->tmpDir . '/old-state';
        $new = $this->tmpDir . '/deep/nested/new-state';
        mkdir($old, 0700, true);

        $this->assertSame("moved {$old} -> {$new}", LegacyPaths::migrate($old, $new));
        clearstatcache();
        $parentMode = fileperms($this->tmpDir . '/deep/nested') & 0700;
        $this->assertSame(0700, $parentMode);
    }

    public function testFailedMoveReportsTheReasonAndLeavesTheOldDirInPlace(): void
    {
        $old = $this->tmpDir . '/old-state';
        $blocker = $this->tmpDir . '/blocker';
        $new = $blocker . '/inner/new-state';
        mkdir($old, 0700, true);
        file_put_contents($old . '/keep.json', 'x');
        file_put_contents($blocker, 'a file where a directory must be');

        $notice = LegacyPaths::migrate($old, $new);

        $this->assertNotNull($notice);
        $this->assertStringStartsWith("could not move {$old} -> {$new}", $notice);
        $this->assertDirectoryExists($old);
        $this->assertSame('x', file_get_contents($old . '/keep.json'));
    }

    private function deleteRecursively(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->deleteRecursively($path . \DIRECTORY_SEPARATOR . $entry);
        }
        @rmdir($path);
    }
}

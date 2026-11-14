<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests\History;

use PHPUnit\Framework\TestCase;
use SugarCraft\Readline\History\FileHistory;

/**
 * S-15 (findings 012, 060, 061): every FileHistory filesystem entry point —
 * history path, lock sidecar, locked write arms, lock-free readers — must
 * refuse or prove out a planted symlink BEFORE any fopen/chmod acts on it.
 *
 * @see \SugarCraft\Readline\History\FileHistory::openVerified()
 */
final class FileHistorySymlinkTest extends TestCase
{
    private string $tmpDir;
    private string $historyFile;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/srl_symlink_' . uniqid();
        mkdir($this->tmpDir, 0700);
        $this->historyFile = $this->tmpDir . '/history';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpDir);
    }

    /**
     * Finding 012: a symlink planted at <path>.lock used to be opened AND
     * path-chmod'd — flock parked on the victim and the chmod flipped the
     * victim's mode. Now: refusal before the first open, and the sidecar is
     * born owner-only via umask, so no followable path chmod exists.
     */
    public function testSymlinkAtLockPathIsRefusedAndVictimPreserved(): void
    {
        $victim = $this->tmpDir . '/victim';
        file_put_contents($victim, "keep\n");
        chmod($victim, 0644);
        if (!@symlink($victim, $this->historyFile . '.lock')) {
            $this->markTestSkipped('symlink() not supported on this platform');
        }

        $h = new FileHistory($this->historyFile);

        try {
            $h->push('hello');
            $h->flush();
            $this->fail('a planted symlink at the lock path must throw before any touch');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('refusing symlinked history lock', $e->getMessage());
        }

        clearstatcache();
        $this->assertFileExists($victim);
        $this->assertSame("keep\n", file_get_contents($victim), 'victim content must be untouched');
        $this->assertSame(0644, fileperms($victim) & 0777, 'victim mode must not be flipped by the lock chmod');
    }

    /**
     * The surviving side of the same fix: a legitimately created lock is
     * owner-only from birth and history keeps working end to end.
     */
    public function testLegitLockSidecarIsBornOwnerOnly(): void
    {
        $h = new FileHistory($this->historyFile);
        $h->push('a');
        $h->flush();

        clearstatcache();
        $lockPath = $this->historyFile . '.lock';
        $this->assertFileExists($lockPath);
        $this->assertSame(0600, fileperms($lockPath) & 0777, 'umask(0077) birth: lock never world-readable');
        $this->assertSame("a\n", file_get_contents($this->historyFile));
    }

    /**
     * Finding 061 read parity: peekLastOnDisk used to fopen through a
     * planted symlink (disclosure). The lock-free reader now refuses before
     * touching — invoked directly here because a constructor on the real
     * file ran earlier, and only the reader arm is under test.
     */
    public function testPeekRefusesPlantedSymlink(): void
    {
        $h = new FileHistory($this->historyFile);
        $h->push('seed');
        $h->flush();

        $victim = $this->tmpDir . '/secret';
        file_put_contents($victim, "stolen\n");
        unlink($this->historyFile);
        if (!@symlink($victim, $this->historyFile)) {
            $this->markTestSkipped('symlink() not supported on this platform');
        }

        $peek = new \ReflectionMethod(FileHistory::class, 'peekLastOnDisk');
        $peek->setAccessible(true);

        try {
            $peek->invoke($h);
            $this->fail('the peek read path must refuse a symlinked history file');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('refusing symlinked history file', $e->getMessage());
            $this->assertStringNotContainsString('stolen', $e->getMessage(), 'refusal must not echo victim content');
        }
    }

    /**
     * Finding 060 structural law: the locked write arms route through
     * openVerified() (single verified fd, regular-file + inode-identity
     * proof) instead of re-opening the path, and the <path>.lock creation
     * runs under a 0077 umask. Raw path re-opens remain only on the two
     * lock-free reader arms, which refuse visibly (test above).
     */
    public function testWriteArmsRouteThroughVerifiedOpen(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../src/History/FileHistory.php');

        $this->assertSame(4, substr_count($src, 'openVerified('), 'definition + append + rewrite-read + merge-read arms');
        $this->assertSame(2, substr_count($src, "fopen(\$this->filePath, 'r')"), 'only load() and peekLastOnDisk() may re-open the path raw');
        $this->assertSame(0, substr_count($src, 'file_get_contents($this->filePath)'), 'no locked read may re-follow the path');
        $this->assertStringContainsString('\\umask(0077)', $src, 'lock sidecar must be born owner-only (no fchmod on this build)');
        $this->assertSame(0, preg_match('/(?<!\.)\bfchmod\s*\(\s*\$/', $src), 'fchmod is absent on this PHP build — no call may exist (comments about it are fine)');
    }
}

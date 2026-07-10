<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests\History;

use PHPUnit\Framework\TestCase;
use SugarCraft\Readline\History\FileHistory;

final class FileHistoryTest extends TestCase
{
    private string $tmpDir;
    private string $historyFile;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/sugar_readline_test_' . uniqid();
        mkdir($this->tmpDir);
        $this->historyFile = $this->tmpDir . '/history';
    }

    protected function tearDown(): void
    {
        $files = glob($this->tmpDir . '/*');
        foreach ($files as $file) {
            unlink($file);
        }
        rmdir($this->tmpDir);
    }

    public function testCreatesFileIfAbsent(): void
    {
        $this->assertFileDoesNotExist($this->historyFile);
        new FileHistory($this->historyFile);
        $this->assertFileExists($this->historyFile);
    }

    public function testPushPersistsToFile(): void
    {
        $h = new FileHistory($this->historyFile);
        $h->push('hello');
        $h->push('world');

        $contents = file_get_contents($this->historyFile);
        $this->assertSame("hello\nworld\n", $contents);
    }

    public function testLoadsExistingEntriesOnConstruction(): void
    {
        file_put_contents($this->historyFile, "existing\nentries\n");

        $h = new FileHistory($this->historyFile);

        $this->assertSame('entries', $h->getPrevious());
        $this->assertSame('existing', $h->getPrevious());
    }

    public function testPushIgnoresDuplicateOfLastEntryOnDisk(): void
    {
        file_put_contents($this->historyFile, "hello\n");

        $h = new FileHistory($this->historyFile);
        $h->push('hello'); // duplicate of on-disk last entry — ignored

        $h->getPrevious(); // should still go to 'hello'
        $this->assertNull($h->getNext()); // then back to live
    }

    public function testDuplicateInMemoryPushDoesNotRewriteFile(): void
    {
        $h = new FileHistory($this->historyFile);
        $h->push('first');
        $h->push('second');
        $h->push('first'); // duplicate of in-memory last entry — ignored, no file write

        $contents = file_get_contents($this->historyFile);
        $this->assertSame("first\nsecond\n", $contents);
    }

    public function testClearRemovesEntriesFromMemoryAndFile(): void
    {
        $h = new FileHistory($this->historyFile);
        $h->push('one');
        $h->push('two');
        $h->clear();

        $this->assertNull($h->getPrevious());
        $this->assertSame('', file_get_contents($this->historyFile));
    }

    public function testResetClearsNavigationWithoutClearingEntries(): void
    {
        $h = new FileHistory($this->historyFile);
        $h->push('a');
        $h->push('b');
        $h->getPrevious(); // navigate to 'b'

        $h->reset();

        // Entries still exist.
        $this->assertSame('b', $h->getPrevious());
        $this->assertSame('a', $h->getPrevious());
    }

    public function testEmptyLineNotPersisted(): void
    {
        $h = new FileHistory($this->historyFile);
        $h->push('');
        $h->push('valid');

        $contents = trim(file_get_contents($this->historyFile));
        $this->assertSame('valid', $contents);
    }

    public function testNewlineOnlyLineNotPersisted(): void
    {
        file_put_contents($this->historyFile, "hello\n\nworld\n");

        $h = new FileHistory($this->historyFile);

        // The empty line should be skipped during load.
        $this->assertSame('world', $h->getPrevious());
        $this->assertSame('hello', $h->getPrevious());
    }

    // =========================================================================
    // Deferred writes — flush()
    // =========================================================================

    private function deferred(): FileHistory
    {
        return new FileHistory($this->historyFile, 0, '', true);
    }

    public function testDeferredPushDoesNotTouchDiskUntilFlush(): void
    {
        $h = $this->deferred();
        $h->push('one');
        $h->push('two');

        // In-memory navigation sees the entries immediately...
        $this->assertSame('two', $h->getPrevious());
        // ...but nothing hits the file until flush().
        $this->assertSame('', file_get_contents($this->historyFile));
    }

    public function testFlushPersistsQueuedEntriesInOrder(): void
    {
        $h = $this->deferred();
        $h->push('one');
        $h->push('two');
        $h->flush();

        $this->assertSame("one\ntwo\n", file_get_contents($this->historyFile));
    }

    public function testFlushIsIdempotent(): void
    {
        $h = $this->deferred();
        $h->push('one');
        $h->flush();
        $h->flush(); // second flush has nothing pending — must not duplicate

        $this->assertSame("one\n", file_get_contents($this->historyFile));
    }

    public function testDestructFlushesPendingEntries(): void
    {
        $h = $this->deferred();
        $h->push('persisted');
        unset($h);

        $this->assertSame("persisted\n", file_get_contents($this->historyFile));
    }

    public function testDeferredEntriesVisibleToFreshInstanceAfterFlush(): void
    {
        $h = $this->deferred();
        $h->push('alpha');
        $h->push('beta');
        $h->flush();

        $fresh = new FileHistory($this->historyFile);
        $this->assertSame('beta', $fresh->getPrevious());
        $this->assertSame('alpha', $fresh->getPrevious());
    }

    public function testDeferredAppendsAfterExistingContent(): void
    {
        file_put_contents($this->historyFile, "old\n");

        $h = $this->deferred();
        $h->push('new');
        $h->flush();

        $this->assertSame("old\nnew\n", file_get_contents($this->historyFile));
    }

    public function testDeferredDedupsAgainstLoadedEntries(): void
    {
        file_put_contents($this->historyFile, "old\n");

        $h = $this->deferred();
        $h->push('old'); // already on disk (loaded into memory) — skipped
        $h->flush();

        $this->assertSame("old\n", file_get_contents($this->historyFile));
    }

    public function testDestroyedCloneDoesNotFlushOriginalsQueue(): void
    {
        // TextPrompt clones its history per operation; a dying clone must not
        // write (and later duplicate) the original's queued entries.
        $h = $this->deferred();
        $h->push('queued');

        $clone = clone $h;
        unset($clone);
        $this->assertSame('', file_get_contents($this->historyFile));

        $h->flush();
        $this->assertSame("queued\n", file_get_contents($this->historyFile));
    }

    public function testClearDropsPendingWrites(): void
    {
        $h = $this->deferred();
        $h->push('doomed');
        $h->clear();
        $h->flush();

        $this->assertSame('', file_get_contents($this->historyFile));
        $this->assertNull($h->getPrevious());
    }

    public function testHistoryFileHasRestrictivePermissions(): void
    {
        $h = new FileHistory($this->historyFile);
        $h->push('secret');

        $this->assertSame(0600, fileperms($this->historyFile) & 0777);
    }

    // =========================================================================
    // Security — temp-file hardening (tempnam + chmod-before-write)
    // =========================================================================

    /** The pre-fix predictable temp path an attacker could target. */
    private function predictedTempPath(): string
    {
        return $this->tmpDir . '/.history.tmp.' . getmypid();
    }

    /**
     * A symlink pre-planted at the old predictable temp path must NOT be
     * followed by the append write. With the predictable-name code,
     * fopen($tempFile, 'w') opened the attacker's symlink target and wrote
     * history content through it (info leak / file corruption).
     */
    public function testSymlinkPreplantDefeatedOnAppend(): void
    {
        $victim = $this->tmpDir . '/victim';
        file_put_contents($victim, 'VICTIM');
        $predicted = $this->predictedTempPath();
        if (!@symlink($victim, $predicted)) {
            $this->markTestSkipped('symlink() not supported on this platform');
        }

        try {
            $h = new FileHistory($this->historyFile, 0, $this->tmpDir);
            $h->push('secret-command');

            // Victim untouched — the write did not follow the symlink.
            $this->assertSame('VICTIM', file_get_contents($victim));
            // History was written to its own (unpredictable) temp, then renamed.
            $this->assertStringContainsString('secret-command', file_get_contents($this->historyFile));
        } finally {
            if (is_link($predicted) || file_exists($predicted)) {
                @unlink($predicted);
            }
        }
    }

    /**
     * A symlink pre-planted at the old predictable temp path must NOT be
     * followed by clear(). With the predictable-name code,
     * file_put_contents($tempFile, '') truncated the symlink's target —
     * arbitrary-file truncation.
     */
    public function testSymlinkPreplantDefeatedOnClear(): void
    {
        $victim = $this->tmpDir . '/victim';
        file_put_contents($victim, 'VICTIM');
        $predicted = $this->predictedTempPath();
        if (!@symlink($victim, $predicted)) {
            $this->markTestSkipped('symlink() not supported on this platform');
        }

        try {
            $h = new FileHistory($this->historyFile, 0, $this->tmpDir);
            $h->clear();

            // Victim not truncated — clear() did not write through the symlink.
            $this->assertSame('VICTIM', file_get_contents($victim));
        } finally {
            if (is_link($predicted) || file_exists($predicted)) {
                @unlink($predicted);
            }
        }
    }

    /**
     * Under a permissive umask a naive create would be world-readable; the
     * chmod-before-write ordering must still yield an owner-only 0600 file.
     */
    public function testHistoryFileIs0600UnderPermissiveUmask(): void
    {
        $oldUmask = umask(0);
        try {
            $h = new FileHistory($this->historyFile, 0, $this->tmpDir);
            $h->push('cmd');

            $this->assertSame(0600, fileperms($this->historyFile) & 0777);
        } finally {
            umask($oldUmask);
        }
    }
}

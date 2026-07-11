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

    // =========================================================================
    // Append fast-path — byte parity with the rewrite fallback
    // =========================================================================

    /**
     * Several sequential push()es via the in-place LOCK_EX append fast-path must
     * lay down exactly the bytes the read-and-rewrite fallback would have, and a
     * fresh instance must reload them newest-first. Pinned bytes so reverting the
     * fast path to the rewrite keeps this green (both produce identical output).
     */
    public function testAppendFastPathMatchesRewriteBytesAndReload(): void
    {
        $h = new FileHistory($this->historyFile);
        $h->push('alpha');
        $h->push('beta');
        $h->push('gamma');

        $this->assertSame("alpha\nbeta\ngamma\n", file_get_contents($this->historyFile));

        $fresh = new FileHistory($this->historyFile);
        $this->assertSame('gamma', $fresh->getPrevious());
        $this->assertSame('beta', $fresh->getPrevious());
        $this->assertSame('alpha', $fresh->getPrevious());
        $this->assertNull($fresh->getPrevious());
    }

    /**
     * When the existing file lacks a trailing newline the fast-path check fails
     * and the rewrite fallback must normalize it before appending — no glued
     * "oldnew" line.
     */
    public function testAppendNormalizesMissingTrailingNewline(): void
    {
        file_put_contents($this->historyFile, 'old'); // no trailing "\n"

        $h = new FileHistory($this->historyFile);
        $h->push('new');

        $this->assertSame("old\nnew\n", file_get_contents($this->historyFile));
    }

    /**
     * Two independent instances appending in turn (each loads, then pushes) must
     * not corrupt the file — the fast path serializes writes under LOCK_EX and
     * only ever appends whole newline-terminated records.
     */
    public function testMultiInstanceAppendDoesNotCorrupt(): void
    {
        $a = new FileHistory($this->historyFile);
        $a->push('one');
        unset($a);

        $b = new FileHistory($this->historyFile);
        $b->push('two');
        unset($b);

        $c = new FileHistory($this->historyFile);
        $c->push('three');
        unset($c);

        $this->assertSame("one\ntwo\nthree\n", file_get_contents($this->historyFile));

        $fresh = new FileHistory($this->historyFile);
        $this->assertSame('three', $fresh->getPrevious());
        $this->assertSame('two', $fresh->getPrevious());
        $this->assertSame('one', $fresh->getPrevious());
    }

    /**
     * The sync-path dup guard reads the last on-disk entry via a bounded tail
     * read. Simulate another process appending an entry AFTER this instance
     * loaded (so the in-memory guard can't catch it): pushing that same line
     * must be suppressed by peekLastOnDisk() finding the true tail of a long
     * file, leaving no duplicate.
     */
    public function testDupGuardFindsTailInLongFile(): void
    {
        $lines = '';
        for ($i = 0; $i < 499; $i++) {
            $lines .= "entry{$i}\n";
        }
        file_put_contents($this->historyFile, $lines);

        $h = new FileHistory($this->historyFile); // loads entry0..entry498

        // Another process appends a fresh entry this instance never loaded.
        file_put_contents($this->historyFile, "entry499\n", FILE_APPEND);
        $expected = $lines . "entry499\n";

        $h->push('entry499'); // in-memory miss; peekLastOnDisk() must catch it

        $this->assertSame($expected, file_get_contents($this->historyFile));
    }

    /**
     * The bounded tail read must still recover the last content line when it is
     * longer than the read chunk (4096 bytes), so the dup guard works on a
     * concurrently-appended oversized entry.
     */
    public function testDupGuardHandlesLineLongerThanChunk(): void
    {
        $long = str_repeat('x', 5000);
        file_put_contents($this->historyFile, "short\n");

        $h = new FileHistory($this->historyFile); // loads only "short"

        // Concurrent append of an oversized entry (> one 4096-byte read chunk).
        file_put_contents($this->historyFile, "{$long}\n", FILE_APPEND);

        $h->push($long); // peekLastOnDisk() must reassemble the tail across chunks

        $this->assertSame("short\n{$long}\n", file_get_contents($this->historyFile));
    }

    // =========================================================================
    // load() dedup + maxHistory eviction — O(1) hash-set parity
    // =========================================================================

    /**
     * Non-adjacent duplicates in the file collapse to the first occurrence's
     * slot, newest-first, exactly as the old in_array() dedup did. Pinned order
     * guards the hash-set rewrite against an O(n) regression.
     */
    public function testLoadDedupsNonAdjacentDuplicates(): void
    {
        // oldest -> newest: A, B, A, C  =>  newest-first result: C, B, A
        file_put_contents($this->historyFile, "A\nB\nA\nC\n");

        $h = new FileHistory($this->historyFile);

        $this->assertSame('C', $h->getPrevious());
        $this->assertSame('B', $h->getPrevious());
        $this->assertSame('A', $h->getPrevious());
        $this->assertNull($h->getPrevious());
    }

    /**
     * maxHistory caps the loaded set to the newest N entries, evicting oldest.
     */
    public function testLoadEvictsToMaxHistory(): void
    {
        file_put_contents($this->historyFile, "A\nB\nC\nD\n");

        $h = new FileHistory($this->historyFile, 2); // keep newest 2

        $this->assertSame('D', $h->getPrevious());
        $this->assertSame('C', $h->getPrevious());
        $this->assertNull($h->getPrevious()); // A, B evicted
    }

    /**
     * Eviction and dedup interact: once the cap evicts an entry, a later
     * duplicate of that evicted line is re-admitted. The hash set must forget
     * evicted entries, matching an in_array() over the live (post-eviction)
     * history. File A,B,C,A @ max 2 => newest-first A,C.
     */
    public function testLoadEvictionReadmitsDuplicateOfEvictedEntry(): void
    {
        file_put_contents($this->historyFile, "A\nB\nC\nA\n");

        $h = new FileHistory($this->historyFile, 2);

        $this->assertSame('A', $h->getPrevious());
        $this->assertSame('C', $h->getPrevious());
        $this->assertNull($h->getPrevious());
    }
}

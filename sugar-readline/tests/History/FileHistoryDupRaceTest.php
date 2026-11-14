<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests\History;

use PHPUnit\Framework\TestCase;
use SugarCraft\Readline\History\FileHistory;

/**
 * S-16 (finding 013): the cross-process duplicate guard must be
 * check-AND-act inside one critical section. The pre-append peek stays as a
 * lock-free fast path; the authoritative re-check runs under the append's
 * LOCK_EX (fast arm) or against the content read under the sidecar lock
 * (rewrite arm), so a duplicate that raced in between cannot survive.
 */
final class FileHistoryDupRaceTest extends TestCase
{
    private string $dir;
    private string $path;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/srl_duprace_' . uniqid('', true);
        mkdir($this->dir, 0700);
        $this->path = $this->dir . '/history.txt';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    private function append(array $lines, bool $deferred = false): void
    {
        $history = new FileHistory($this->path, 0, '', $deferred);
        $method = new \ReflectionMethod(FileHistory::class, 'appendLinesAtomically');
        $method->setAccessible(true);
        $method->invoke($history, $lines);
    }

    /**
     * The race made deterministic: the outer peek already said "new", a
     * foreign writer appended the same line, and only the in-lock re-check
     * stands between us and a permanent duplicate in the append-only file.
     */
    public function testInLockGuardKillsRacingAppend(): void
    {
        file_put_contents($this->path, "x\n");
        $this->append(['x']);
        $this->assertSame("x\n", file_get_contents($this->path), 'the racing duplicate must not survive the append');
    }

    public function testGuardNeverEatsGenuineAppends(): void
    {
        file_put_contents($this->path, "a\n");
        $this->append(['b']);
        $this->assertSame("a\nb\n", file_get_contents($this->path));
    }

    /**
     * Adjacent-only semantics, one notch: only the LEADING payload entry is
     * re-checked against the tail — the batch's own internal repeats are the
     * in-memory guard's business, not something the writer should invent.
     */
    public function testBatchDedupesOnlyLeadingAdjacentEntry(): void
    {
        file_put_contents($this->path, "p\n");
        $this->append(['p', 'q', 'p']);
        $this->assertSame("p\nq\np\n", file_get_contents($this->path));
    }

    public function testBlankTailLinesAreSkippedByTheGuard(): void
    {
        // Mirrors peekLastOnDisk semantics: the tail line, not the trailing
        // bytes, is what a duplicate is measured against.
        file_put_contents($this->path, "x\n\n");
        $this->append(['x']);
        $this->assertSame("x\n\n", file_get_contents($this->path));
    }

    /**
     * The deferred path was wider still: flush() pushed the whole queue with
     * no tail check at all. Race a foreign writer into the queue's window.
     */
    public function testFlushPathDedupesAgainstForeignAppend(): void
    {
        $history = new FileHistory($this->path, 0, '', true);
        $history->push('x'); // queued; nothing on disk yet

        // A competing process lands the same line before our flush().
        file_put_contents($this->path, "x\n");

        $history->flush();
        $this->assertSame("x\n", file_get_contents($this->path), 'the flush batch re-checks the tail in-lock');
    }

    /**
     * The rewrite arm (file not newline-terminated → temp+rename) carries the
     * same guard against the content read under the sidecar lock.
     */
    public function testRewriteArmDedupesLeadingDuplicate(): void
    {
        file_put_contents($this->path, 'x'); // no trailing newline — forces the fallback
        $this->append(['x']);
        // Finding 064 changed the shape: an unterminated 'x' is a crash
        // fragment the rewrite DROPS, then writes the entry terminated. The
        // old pin expected the fragment to count as the tail and be skipped,
        // leaving "x" glued-on; either way no "xx" duplicate is ever created.
        $this->assertSame("x\n", file_get_contents($this->path), 'fragment dropped, entry written terminated');
    }

    public function testRewriteArmDedupesAgainstTerminatedDuplicateBehindFragment(): void
    {
        // Fallback forced by a torn tail BEHIND a terminated duplicate: the
        // in-lock guard answers with the last terminated line ("x"), shifts
        // the batch, and discards the temp file without rewriting.
        file_put_contents($this->path, "x\nfrag");
        $this->append(['x']);
        $this->assertSame("x\nfrag", file_get_contents($this->path), 'skip writes nothing, mirroring the outer guard');
    }

    public function testRewriteArmAppendsWhenTailDiffers(): void
    {
        file_put_contents($this->path, 'y');
        $this->append(['x']);
        // Finding 064: the unterminated 'y' is a fragment — dropped, never
        // normalized. A glued "yx" is likewise impossible.
        $this->assertSame("x\n", file_get_contents($this->path));
    }

    public function testRewriteArmRepairsTailAndAppendsWhenTailDiffers(): void
    {
        // A terminated different tail behind the fragment: guard appends,
        // rewrite repairs the file to whole terminated records.
        file_put_contents($this->path, "y\nfrag");
        $this->append(['x']);
        $this->assertSame("y\nx\n", file_get_contents($this->path));
    }

    /**
     * Structural law: one shared tail implementation, and the peek remains a
     * single-call-site fast path — a future edit must not reintroduce a
     * second, drifting copy of the tail scan or move the authority back out
     * of the lock.
     */
    public function testSingleTailImplementationAndPeekStaysFastPathOnly(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../src/History/FileHistory.php');
        $this->assertSame(1, substr_count($src, '$this->peekLastOnDisk('), 'peek is called from the push fast path only');
        $this->assertSame(1, substr_count($src, 'private function peekLastOnDisk'));
        $this::assertGreaterThanOrEqual(2, substr_count($src, 'self::tailLineOfStream('), 'stream tail helper serves peek AND the locked append');
        $this->assertSame(1, substr_count($src, 'private static function tailLineOfStream'));
        $this->assertSame(1, substr_count($src, 'private static function tailLineOfContent'));
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests\History;

use PHPUnit\Framework\TestCase;
use SugarCraft\Readline\History\FileHistory;

/**
 * S-42 (finding 063): persist-then-swap on the failure paths. The capped
 * push arm, flush() and clear() must commit the disk BEFORE mutating the
 * in-memory view (and, for flush, before draining the queue), so a caller
 * that catches a write failure is left with memory agreeing with the file —
 * never a store that contradicts its own disk.
 *
 * The injected-failure seam is the S-15 lock-sidecar refusal: a symlink at
 * <path>.lock makes withWriteLock throw before it can touch anything.
 */
final class FileHistoryPersistOrderTest extends TestCase
{
    private string $dir;
    private string $path;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/srl_persist_' . uniqid('', true);
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

    private function poisonLock(): void
    {
        // A previous write already BORN the sidecar; the swap target of the
        // refusal is the path, so replace the file with a dangling symlink.
        @unlink($this->path . '.lock');
        if (!symlink($this->dir . '/victim', $this->path . '.lock')) {
            $this->fail('unable to plant the lock symlink for the failure seam');
        }
        clearstatcache();
    }

    private function unpoisonLock(): void
    {
        @unlink($this->path . '.lock');
        clearstatcache();
    }

    private function diskBytes(): string
    {
        return file_exists($this->path) ? (string) file_get_contents($this->path) : '';
    }

    private function expectRefusal(callable $work): void
    {
        $threw = false;
        try {
            $work();
        } catch (\RuntimeException $e) {
            $threw = true;
            $this->assertStringContainsString('history lock', $e->getMessage());
        }
        $this->assertTrue($threw, 'the planted lock symlink must refuse the write');
    }

    private function trimOverflowOf(FileHistory $history): int
    {
        $prop = new \ReflectionProperty(FileHistory::class, 'trimOverflow');
        $prop->setAccessible(true);

        return (int) $prop->getValue($history);
    }

    public function testCappedMergeFailureLeavesMemoryFileAndCheckpointUntouched(): void
    {
        $history = new FileHistory($this->path, 1);
        $history->push('a');

        // cap=1: the next push is the evicting one and takes the merge arm.
        // The old order had already prepended 'b' (and dropped 'a' to the
        // cap) before touching disk — memory contradicting the file.
        $this->poisonLock();
        $this->expectRefusal(fn () => $history->push('b'));
        $this->assertSame(['a'], $history->entries());
        $this->assertSame("a\n", $this->diskBytes());
        $this->assertSame(0, $this->trimOverflowOf($history));

        // Removing the poison and retrying lands the same push cleanly.
        $this->unpoisonLock();
        $history->push('b');
        $this->assertSame(['b'], $history->entries());
        $this->assertSame("b\n", $this->diskBytes());
    }

    public function testCappedAppendFailureAdvancesNoCheckpoint(): void
    {
        // cap=2 with a checkpoint already sitting at overflow=1: the next
        // eviction fires the merge arm. A failure there must not grow the
        // counter past the committed state (a skip-ahead would let the file
        // drift further than the 2x bound documents).
        $history = new FileHistory($this->path, 2);
        $history->push('a');
        $history->push('b'); // window full from here on
        $history->push('c'); // evicts, overflow -> 1 (append arm)
        $this->assertSame(1, $this->trimOverflowOf($history));

        $this->poisonLock();
        $this->expectRefusal(fn () => $history->push('d'));
        $this->assertSame(['c', 'b'], $history->entries());
        $this->assertSame(1, $this->trimOverflowOf($history));
        $this->assertSame("a\nb\nc\n", $this->diskBytes());

        $this->unpoisonLock();
        $history->push('d'); // retried checkpoint commits
        $this->assertSame(['d', 'c'], $history->entries());
        $this->assertSame(0, $this->trimOverflowOf($history));
        $this->assertSame("c\nd\n", $this->diskBytes());
    }

    public function testClearFailureKeepsTheWholeStore(): void
    {
        $history = new FileHistory($this->path, 0);
        $history->push('a');
        $history->push('b');

        $this->poisonLock();
        $this->expectRefusal(fn () => $history->clear());

        // Memory-first clear would have emptied the view while the file —
        // and the next push's dedupe law — still carried everything.
        $this->assertSame(['b', 'a'], $history->entries());
        $this->assertSame("a\nb\n", $this->diskBytes());

        $this->unpoisonLock();
        $history->clear();
        $this->assertSame([], $history->entries());
        $this->assertSame('', $this->diskBytes());
    }

    public function testFailedFlushLeavesTheQueueRetryable(): void
    {
        $history = new FileHistory($this->path, 0, '', true);
        $history->push('a');
        $history->push('b');

        $this->poisonLock();
        $this->expectRefusal(fn () => $history->flush());

        $this->assertSame('', $this->diskBytes());
        $this->assertSame(['b', 'a'], $history->entries());

        // Queue-drain-before-write would have dissolved the batch: the
        // retry would then persist NOTHING. Persist-then-swap keeps it live.
        $this->unpoisonLock();
        $history->flush();
        $this->assertSame("a\nb\n", $this->diskBytes());
    }

    public function testFailedCappedFlushKeepsTheQueueRetryable(): void
    {
        $history = new FileHistory($this->path, 5, '', true);
        $history->push('a');
        $history->push('b');

        $this->poisonLock();
        $this->expectRefusal(fn () => $history->flush()); // capped -> merge arm

        $this->unpoisonLock();
        $history->flush();
        $this->assertSame("a\nb\n", $this->diskBytes());
    }
}

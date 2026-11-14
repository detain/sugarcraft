<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests\History;

use PHPUnit\Framework\TestCase;
use SugarCraft\Readline\History\FileHistory;

/**
 * S-18 (findings 016, 062): capped pushes must stop paying a full
 * read-merge-rewrite per Enter, and the per-push membership test must ride
 * the resident set the loader already builds.
 */
final class FileHistoryPerfTest extends TestCase
{
    private string $tmpDir;
    private string $historyFile;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/srl_perf_' . uniqid('', true);
        mkdir($this->tmpDir, 0700);
        $this->historyFile = $this->tmpDir . '/history.txt';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
    }

    /**
     * Finding 016: the bounded rewrite fires once per eviction window, not
     * per push. The temp+rename rewrite changes the file's inode; plain
     * appends keep it — the inode therefore counts the rewrites.
     */
    public function testCappedPushesDeferTheBoundedRewriteUntilTheWindowFills(): void
    {
        $h = new FileHistory($this->historyFile, 3);
        $h->push('e1');
        $inode = (int) (stat($this->historyFile)['ino'] ?? 0);
        self::assertGreaterThan(0, $inode);

        $h->push('e2');
        $h->push('e3');
        $this->assertSame($inode, (int) stat($this->historyFile)['ino'], 'fill phase must never rewrite');

        $h->push('e4'); // evicts e1 -> overflow 1
        $h->push('e5'); // overflow 2
        $this->assertSame($inode, (int) stat($this->historyFile)['ino'], 'trim must defer until a full eviction window');

        // The pre-trim file is the documented bounded superset: e1 has left
        // memory but the sweep has not run yet.
        $raw = (string) file_get_contents($this->historyFile);
        $this->assertStringContainsString('e1', $raw, 'superset before trim is the honest in-between state');

        $h->push('e6'); // overflow 3 == cap -> the one deferred rewrite fires
        $this->assertNotSame($inode, (int) stat($this->historyFile)['ino'], 'exactly one rewrite at the threshold');

        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($this->historyFile)), static fn ($l) => $l !== ''));
        $this->assertSame(['e4', 'e5', 'e6'], $lines, 'trim still lands the merged bounded view');
    }

    /**
     * flush() is the persistence checkpoint: a deferred trim that has not
     * reached its window must still deliver the bounded view on flush.
     */
    public function testFlushForcesTheDeferredTrimImmediately(): void
    {
        $h = new FileHistory($this->historyFile, 3);
        foreach (['f1', 'f2', 'f3', 'f4'] as $entry) {
            $h->push($entry); // one eviction, window (3) not filled
        }
        $raw = (string) file_get_contents($this->historyFile);
        $this->assertStringContainsString('f1', $raw);

        $h->flush();

        $lines = array_values(array_filter(explode("\n", $raw = (string) file_get_contents($this->historyFile)), static fn ($l) => $l !== ''));
        $this->assertSame(['f2', 'f3', 'f4'], $lines, 'flush must not leave the superseded head on disk');
    }

    /**
     * Finding 062: the resident set must mirror the live window EXACTLY —
     * including eviction. A line that left the window through the cap is
     * re-admitted precisely as an in_array() over the live window would be.
     */
    public function testResidentMembershipMirrorsEviction(): void
    {
        $h = new FileHistory($this->historyFile, 2);
        $h->push('a');
        $h->push('b');

        $h->push('a'); // still retained ([b,a]) -> deduped
        $this->assertSame(['b', 'a'], array_slice($h->entries(), 0, 2));

        $h->push('c'); // evicts the older slot
        $h->push('a'); // evicted line must be RE-ADMISSIBLE (mirror law)
        $entries = $h->entries();
        $this->assertSame('a', $entries[0], 're-pushed evicted line returns to the newest slot');

        $h->flush();
        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($this->historyFile)), static fn ($l) => $l !== ''));
        $this->assertSame(['c', 'a'], $lines, 'trimmed file rides the same union the merge has always produced');
    }

    /**
     * Structural laws (the O(1) promise and its mirror): no in_array()
     * membership scan survives in the push path, and the resident set owns
     * the test.
     */
    public function testResidentSetStructuralLaws(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/History/FileHistory.php');

        $this->assertSame(0, preg_match('/\bin_array\s*\(\s*[$a-zA-Z\\\\]/', $src), 'membership scans are gone (finding 062) — no CALL SHAPE survives; prose mentions of the old scan are fine');
        $this->assertGreaterThanOrEqual(1, substr_count($src, 'isset($this->seen[$line])'), 'inMemoryContains rides the resident set');
        $this->assertGreaterThanOrEqual(2, substr_count($src, '$this->pushResident('), 'load() and push() share one mirror');
        $this->assertStringContainsString('private int $trimOverflow = 0;', $src, 'the deferred-trim window is real state');
    }
}

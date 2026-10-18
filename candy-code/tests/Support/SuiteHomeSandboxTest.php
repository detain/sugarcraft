<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Support\SafeDelete;

/**
 * BEHAVIORAL PINS FOR THE HOME-SANDBOX COURTESY PRUNE (rv7 F-1).
 *
 * The source scan in {@see SafeDeleteSweepHardeningTest} proves the gate
 * sits ahead of the walk in the text; it cannot prove the walk leaves its
 * own licence alone — which is exactly how the inline loop survived review
 * while deleting the sentinel, after one run past the window, at install
 * time. This file drives {@see SuiteHomeSandbox::pruneCourtesy()} against a
 * fixture tree with backdated mtimes and asserts what the filesystem looks
 * like afterwards: sentinel kept, stale entries gone, unstamped base whole,
 * and — the crux — a SECOND run still pruning, because the first one did
 * not disarm it.
 */
final class SuiteHomeSandboxTest extends TestCase
{
    private string $base;

    /** Two days old: unambiguously past the one-day window at $this->now(). */
    private int $oldTs;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/sc_courtesy_' . bin2hex(random_bytes(6));
        mkdir($this->base, 0o700, true);
        $this->oldTs = time() - (2 * SuiteHomeSandbox::SECONDS_PER_DAY);
    }

    protected function tearDown(): void
    {
        self::purgeCourtesyTree($this->base);
    }

    public function testTheCourtesyPruneKeepsItsSentinelAndStaysLicensedForTheNextRun(): void
    {
        $exports = $this->base . '/exports';
        mkdir($exports, 0o700, true);
        file_put_contents($exports . '/stale.md', 'yesterday');
        file_put_contents($this->base . '/stale.txt', 'yesterday');
        file_put_contents($this->base . '/fresh.txt', 'today');
        $this->assertTrue(SafeDelete::stampSentinel($this->base));
        // The defect shape: by the time a run happens, the stamp has aged too.
        touch($this->base . '/' . SafeDelete::SENTINEL, $this->oldTs);
        touch($exports . '/stale.md', $this->oldTs);
        touch($this->base . '/stale.txt', $this->oldTs);
        clearstatcache();

        SuiteHomeSandbox::pruneCourtesy($this->base, time());
        clearstatcache();

        $this->assertFileExists($this->base . '/' . SafeDelete::SENTINEL, 'F-1: the walk must never unlink the sentinel that licenses it');
        $this->assertTrue(SafeDelete::carriesSentinel($this->base), 'the base must still read as creator-stamped after a prune');
        $this->assertFileDoesNotExist($this->base . '/stale.txt');
        $this->assertFileDoesNotExist($exports . '/stale.md', 'the walk recurses: nested stale regular files go too');
        $this->assertDirectoryExists($exports, 'directories survive; only the entries age out');
        $this->assertFileExists($this->base . '/fresh.txt', 'an in-flight run is hours under the blade');

        // THE CRUX, SECOND RUN: a fresh stale entry must still meet the blade.
        // Pre-cure this is where the suite silently lost its cleanup forever.
        file_put_contents($this->base . '/stale-again.txt', 'second yesterday');
        touch($this->base . '/stale-again.txt', $this->oldTs);
        clearstatcache();
        SuiteHomeSandbox::pruneCourtesy($this->base, time());
        clearstatcache();
        $this->assertFileDoesNotExist($this->base . '/stale-again.txt', 'the first prune must not have disabled the second');
    }

    public function testTheCourtesyPruneRefusesABaseItDidNotStamp(): void
    {
        file_put_contents($this->base . '/stale.txt', 'planted, not ours');
        touch($this->base . '/stale.txt', $this->oldTs);
        clearstatcache();

        SuiteHomeSandbox::pruneCourtesy($this->base, time());
        clearstatcache();

        $this->assertFileExists($this->base . '/stale.txt', 'no sentinel: the refuse-unstamped gate leaves the whole base alone');
    }

    /**
     * This file's OWN cleanup: is_link first, unlink names, recurse dirs.
     * Named uniquely against DuplicatedTestHelperDrift's same-name scan.
     */
    private static function purgeCourtesyTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach ((array) scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_link($path) || is_file($path)) {
                @unlink($path);

                continue;
            }
            self::purgeCourtesyTree($path);
            @rmdir($path);
        }
        @rmdir($dir);
    }
}

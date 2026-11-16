<?php

declare(strict_types=1);

namespace SugarCraft\Git\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Git\Tests\Concerns\RecursiveDirCleanup;

/**
 * ci-wave-5 5C pins for RecursiveDirCleanup.
 *
 * The Windows job exited 1 under failOnWarning with zero test failures:
 * unlink 'Permission denied' + rmdir 'Directory not empty' diagnostics from
 * the cleanup trait's teardown pass. This file proves the cure on every
 * constructible leg:
 *
 *  - Linux-constructible warning class: a non-writable parent directory
 *    gates POSIX unlink exactly as the read-only attribute gates it on NTFS,
 *    and the failed unlink cascades 'Directory not empty' up the rmdir
 *    chain - the old flat implementation let both warnings escape
 *    (mutation-proven: see the lane report). The real-fixture pin below
 *    shows the chmod-repair retry consumes the condition with ZERO escaped
 *    warnings (failOnWarning="true" would turn any escape red).
 *  - Windows-only-constructible legs: a chmod-repairable condition always
 *    heals before the budget is spent, so no rootless Linux path can force
 *    a true survivor or a repeated real failure. The raw-operation seams in
 *    the trait simulate those classes deterministically: the loud-final pin
 *    proves the named-path throw, the chronology pin proves the retry loop
 *    actually re-attempts, repairs via chmod BEFORE the retry, and re-scans
 *    children on each retry pass.
 */
final class RecursiveDirCleanupTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/rdc_' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            // The real trait sweeps its own fixtures, including the survivors
            // a broken cure would leave behind - and it does so through the
            // same loud channel, so a regressed cleanup fails here by name.
            (new RecursiveDirHarness())->clean($this->root);
        }
    }

    public function testAPlainTreeIsRemovedWithoutLeavingAnything(): void
    {
        mkdir($this->root . '/objects/pack', 0o777, true);
        file_put_contents($this->root . '/objects/pack/pack-abc.idx', 'x');
        file_put_contents($this->root . '/HEAD', "ref: refs/heads/main\n");

        (new RecursiveDirHarness())->clean($this->root);

        $this->assertDirectoryDoesNotExist($this->root);
    }

    /**
     * The Linux constructible: unlink() inside a 0500 directory warns
     * 'Permission denied' and the whole rmdir chain above it warns
     * 'Directory not empty' - the exact two diagnostic classes the Windows
     * run leaked. The repair pass chmods the survivors writable and the
     * re-scan pass removes everything, with no warning reaching the test
     * result (any escape turns this run red via failOnWarning).
     */
    public function testAPermissionDeniedUnlinkIsConsumedByTheChmodRepairRetry(): void
    {
        $locked = $this->root . '/objects';
        mkdir($locked, 0o777, true);
        file_put_contents($locked . '/obj-file', 'x');
        chmod($locked, 0o500); // non-writable parent: the POSIX gate class

        (new RecursiveDirHarness())->clean($this->root);

        $this->assertDirectoryDoesNotExist($this->root, 'the repair retry consumed the condition');
    }

    public function testATreeThatIsAlreadyGoneIsNotAWarning(): void
    {
        $harness = new RecursiveDirHarness();
        $missing = $this->root . '/never-created';

        $harness->clean($missing);
        $this->assertDirectoryDoesNotExist($missing);

        $harness->clean($this->root); // the root itself never existed either
        $this->assertDirectoryDoesNotExist($this->root);
    }

    /**
     * The loud final: after the full budget, anything left over is reported
     * by name in a thrown RuntimeException - never a stray PHP warning and
     * never silent success. Simulated through the raw-operation seams: both
     * Windows fault classes refuse every attempt while the real files stay.
     */
    public function testSurvivorsAfterTheFullBudgetThrowNamingEveryPath(): void
    {
        mkdir($this->root . '/folder', 0o777, true);
        file_put_contents($this->root . '/folder/kept.txt', 'x');
        file_put_contents($this->root . '/direct.txt', 'x');

        $message = '';
        try {
            (new StubbornCleanup())->clean($this->root);
            $this->fail('expected the exhausted cleanup to fail loud');
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
        }

        $this->assertStringContainsString($this->root . '/direct.txt', $message);
        $this->assertStringContainsString($this->root . '/folder/kept.txt', $message);
        $this->assertStringContainsString($this->root . '/folder', $message);
        $this->assertStringContainsString('Permission denied', $message, 'the captured unlink diagnostic rides the report');
        $this->assertStringContainsString('Directory not empty', $message, 'the captured rmdir diagnostic rides the report');
        // tearDown removes the surviving real tree through the healthy trait.
    }

    /**
     * The retry loop runs: the simulated first-attempt failures are retried
     * (so each file unlink and each directory rmdir is seen exactly twice),
     * the repair chmod is sequenced BEFORE the retry, and every retry pass
     * re-derives the entry list (scandir count). Windows-only failure
     * classes, deterministically pinned through the seams.
     */
    public function testTheRetryLoopRepairsChmodsFirstAndRescansBeforeReattempting(): void
    {
        mkdir($this->root . '/folder', 0o777, true);
        file_put_contents($this->root . '/folder/kept.txt', 'x');
        file_put_contents($this->root . '/direct.txt', 'x');

        $stub = new StubbornThenHealsCleanup();
        $stub->clean($this->root);

        $this->assertDirectoryDoesNotExist($this->root, 'the simulated condition was consumed by the loop');

        $file = $this->root . '/direct.txt';
        $positions = [];
        $scandirs  = 0;
        foreach ($stub->log as $index => $line) {
            if ($line === 'unlink ' . $file) {
                $positions[] = $index;
            }
            if ($line === 'scandir ' . $this->root) {
                $scandirs++;
            }
        }

        $this->assertCount(2, $positions, 'the failed unlink is retried exactly once more and succeeds');
        $repair = array_search('chmod ' . $file, $stub->log, true);
        $this->assertIsInt($repair, 'the survivor is made writable before the retry');
        $this->assertGreaterThan($positions[0], $repair, 'the repair lands after the failed attempt');
        $this->assertLessThan($positions[1], $repair, 'the repair lands before the retry');
        $this->assertGreaterThanOrEqual(2, $scandirs, 'each retry pass re-derives the entry list');

        $dirPositions = [];
        foreach ($stub->log as $index => $line) {
            if ($line === 'rmdir ' . $this->root . '/folder') {
                $dirPositions[] = $index;
            }
        }
        $this->assertCount(2, $dirPositions, 'the failed rmdir is retried exactly once more and succeeds');
    }
}

/**
 * Public wrapper over the protected removeDir, using the REAL operations.
 */
final class RecursiveDirHarness
{
    use RecursiveDirCleanup;

    public function clean(string $dir): void
    {
        $this->removeDir($dir);
    }
}

/**
 * Seam double: both Windows fault classes refuse EVERY attempt, mirroring a
 * permanently held NTFS entry; real files stay put, so the budget exhausts
 * and the loud final must name them.
 */
final class StubbornCleanup
{
    use RecursiveDirCleanup;

    public function clean(string $dir): void
    {
        $this->removeDir($dir);
    }

    protected function cleanupUnlink(string $path): bool
    {
        trigger_error('unlink(' . $path . '): Permission denied', E_USER_WARNING);
        return false;
    }

    protected function cleanupRmdir(string $dir): bool
    {
        trigger_error('rmdir(' . $dir . '): Directory not empty', E_USER_WARNING);
        return false;
    }
}

/**
 * Seam double: every file unlink and directory rmdir fails on its FIRST
 * attempt per path (the NTFS attribute/handle class) and then performs the
 * real operation - so the healthy loop consumes the condition within its
 * budget while the call log proves the repair-and-rescan chronology.
 */
final class StubbornThenHealsCleanup
{
    use RecursiveDirCleanup;

    /** @var list<string> chronological 'op path' log */
    public array $log = [];

    /** @var array<string, true> */
    private array $adverseUnlinks = [];

    /** @var array<string, true> */
    private array $adverseRmdirs = [];

    public function clean(string $dir): void
    {
        $this->removeDir($dir);
    }

    protected function cleanupScandir(string $dir): array|false
    {
        $this->log[] = 'scandir ' . $dir;
        return scandir($dir);
    }

    protected function cleanupUnlink(string $path): bool
    {
        $this->log[] = 'unlink ' . $path;
        if (!isset($this->adverseUnlinks[$path])) {
            $this->adverseUnlinks[$path] = true;
            trigger_error('unlink(' . $path . '): Permission denied', E_USER_WARNING);
            return false;
        }
        return unlink($path);
    }

    protected function cleanupRmdir(string $dir): bool
    {
        $this->log[] = 'rmdir ' . $dir;
        if (!isset($this->adverseRmdirs[$dir])) {
            $this->adverseRmdirs[$dir] = true;
            trigger_error('rmdir(' . $dir . '): Directory not empty', E_USER_WARNING);
            return false;
        }
        return rmdir($dir);
    }

    protected function cleanupChmod(string $path, int $mode): bool
    {
        $this->log[] = 'chmod ' . $path;
        return chmod($path, $mode);
    }
}

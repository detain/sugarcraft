<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\WindowsSwap;

/**
 * C-TUI-6.e — the Windows relaunch dance's two honest halves.
 *
 * THE SEPARATION LAW under test: argv construction and the fixed-literal helper
 * are pure data and EXEC as data here (argv shape, literal-vs-path isolation,
 * php -l on the helper, and the helper itself run against temp files on this
 * Linux box — the retry/rename/log/exit protocol is OS-portable, so its logic
 * is genuinely executed, not cert-stubbed). What remains UNCONFIRMED-BY-RUN on
 * purpose: the NT image-lock semantics themselves — that renaming the staged
 * file onto a running phar really does fail WHILE the parent holds the image
 * and succeed AFTER it exits can only be confirmed on real NT; this file pins
 * the mechanism that consumes that fact, matching the C-TUI-6.a/6.d welded
 * precedent (static-cert and injected-fake pins for Windows legs, real exec for
 * pure logic).
 */
final class WindowsSwapTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/ccmp6e-swap-' . \uniqid('', true);
        \mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (\scandir($this->dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $path = $this->dir . '/' . $entry;
                if (\is_dir($path)) {
                    foreach (\scandir($path) ?: [] as $inner) {
                        if ($inner !== '.' && $inner !== '..') {
                            @\unlink($path . '/' . $inner);
                        }
                        @\rmdir($path);
                    }
                } else {
                    @\unlink($path);
                }
            }
        }
        @\rmdir($this->dir);
    }

    public function testLaunchCommandCarriesPathsAsArgvAndNeverAsCode(): void
    {
        $needleNew = 'injected-new-' . \uniqid('sw', true) . '.phar.new';
        $needlePhar = 'injected-phar-' . \uniqid('sw', true) . '.phar';

        $command = WindowsSwap::launchCommand(4242, $needleNew, $needlePhar);

        self::assertSame(\PHP_BINARY, $command[0]);
        self::assertSame('-r', $command[1]);
        // The code slot is the FIXED literal, byte-identical to the constant —
        // nothing derived from arguments ever reaches it.
        self::assertSame(WindowsSwap::HELPER, $command[2]);
        // Paths travel as separate data slots AFTER the literal.
        self::assertSame('4242', $command[3]);
        self::assertSame($needleNew, $command[4]);
        self::assertSame($needlePhar, $command[5]);
        self::assertCount(6, $command);

        // Injection class, pinned from the other side: the uniqid sentinels are
        // absent from every byte of the code literal.
        self::assertStringNotContainsString(\substr($needleNew, 0, 30), WindowsSwap::HELPER);
        self::assertStringNotContainsString(\substr($needlePhar, 0, 30), WindowsSwap::HELPER);
        self::assertStringNotContainsString('4242', WindowsSwap::HELPER);
        // And the literal carries no interpolation syntax at all — it is a
        // nowdoc constant, so even a stray `$` in a path could not expand.
        self::assertSame(WindowsSwap::HELPER, WindowsSwap::launchCommand(1, 'a', 'b')[2]);
    }

    public function testHelperSourcePassesLintAsAStandaloneProgram(): void
    {
        $file = $this->dir . '/helper-lint.php';
        \file_put_contents($file, "<?php\n" . WindowsSwap::HELPER . "\n");

        [$status, , $errOut] = $this->drainSpawn([\PHP_BINARY, '-l', $file]);

        self::assertSame(0, $status, 'the fixed helper literal must parse standalone: ' . $errOut);
    }

    public function testHelperRetryLiteralsStayMarriedToTheClassConstants(): void
    {
        // The helper cannot autoload the class (detached `php -r` carries no
        // bootstrap), so it spells the cadence and the default budget inline.
        // These pins are the marriage: drift the constant WITHOUT the literal
        // (or vice versa) and the documented contract goes red here.
        self::assertStringContainsString('$retryUs = ' . (string) WindowsSwap::RETRY_INTERVAL_US . ';', WindowsSwap::HELPER);
        self::assertStringContainsString(': ' . \var_export(WindowsSwap::RETRY_BUDGET_SECONDS, true) . ';', WindowsSwap::HELPER);
        self::assertSame(200_000, WindowsSwap::RETRY_INTERVAL_US);
        self::assertSame(60.0, WindowsSwap::RETRY_BUDGET_SECONDS);
    }

    public function testHelperAgainstAnExitedParentRenamesAndLogsOnThisRunner(): void
    {
        // Real EXEC of the production shape: staged bytes, an already-reaped
        // (thus "exited") parent pid, no budget override — argv[4] absent means
        // the helper runs its default 60 s contract. The rename lands on
        // attempt one; the assertions are directional, so a swapped argv order
        // (rename target->stage) reddens them.
        $dead = $this->deadChildPid();
        $stage = $this->dir . '/live.phar.new';
        $target = $this->dir . '/live.phar';
        $stagedBytes = 'STAGED-NEW-' . \uniqid('sn', true);
        \file_put_contents($stage, $stagedBytes);
        \file_put_contents($target, 'RUNNING-PHAR');

        [$status, $out, $err] = $this->drainSpawn([\PHP_BINARY, '-r', WindowsSwap::HELPER, (string) $dead, $stage, $target]);

        self::assertSame(0, $status, 'helper must exit 0 on swap: ' . $out . $err);
        self::assertSame($stagedBytes, \file_get_contents($target));
        \clearstatcache(true, $stage);
        self::assertFalse(\is_file($stage), 'the stage is consumed by the rename');
        $log = (string) \file_get_contents($target . '.swap.log');
        self::assertStringContainsString('swap helper started; waiting for parent pid ' . $dead, $log);
        self::assertStringContainsString('swap complete after 1 attempt(s)', $log);
    }

    public function testTargetThatNeverFreesLeavesTheStageIntactAndGivesUpLoudly(): void
    {
        // Deterministic "locked image" on every OS: rename(FILE, non-empty-dir)
        // always fails, exactly like NT's delete-pending hold on the phar. The
        // short budget is the test-seam argv[4] leg production never passes.
        $dead = $this->deadChildPid();
        $stage = $this->dir . '/locked.phar.new';
        $targetDir = $this->dir . '/locked.phar';
        $logTarget = $this->dir . '/locked.phar';
        \mkdir($targetDir, 0700);
        \file_put_contents($targetDir . '/keep.txt', 'DIR-MUST-STAY');
        \file_put_contents($stage, 'STAGED-BYTES');

        [$status, , $err] = $this->drainSpawn([\PHP_BINARY, '-r', WindowsSwap::HELPER, (string) $dead, $stage, $targetDir, '0.4']);

        self::assertSame(1, $status, 'gave-up leg must exit 1: ' . $err);
        self::assertSame('STAGED-BYTES', \file_get_contents($stage), 'no partial swap: the stage survives intact');
        self::assertSame('DIR-MUST-STAY', \file_get_contents($targetDir . '/keep.txt'), 'the never-freed target was never disturbed');
        $log = (string) \file_get_contents($logTarget . '.swap.log');
        self::assertStringContainsString('gave up after', $log);
        self::assertStringContainsString('left staged for manual swap', $log);
        self::assertStringContainsString('attempt 1: ', $log, 'each failed rename is logged with its reason');
    }

    public function testVanishedStageExitsQuietlyWithoutASwap(): void
    {
        $dead = $this->deadChildPid();
        $stage = $this->dir . '/gone.phar.new'; // never created
        $target = $this->dir . '/gone.phar';
        \file_put_contents($target, 'RUNNING-PHAR');

        [$status, , ] = $this->drainSpawn([\PHP_BINARY, '-r', WindowsSwap::HELPER, (string) $dead, $stage, $target]);

        self::assertSame(0, $status);
        self::assertSame('RUNNING-PHAR', \file_get_contents($target));
        self::assertStringContainsString('nothing staged', (string) \file_get_contents($target . '.swap.log'));
    }

    public function testEmptyPathSlotsAnswerUsageWithExitTwo(): void
    {
        [$status, , $err] = $this->drainSpawn([\PHP_BINARY, '-r', WindowsSwap::HELPER, '7', '', '']);

        self::assertSame(2, $status);
        self::assertStringContainsString('usage:', $err);
    }

    public function testLaunchSpawnsTheDetachedHelperAndItLandsTheSwapOutOfProcess(): void
    {
        // The REAL launch() leg end-to-end on this box: spawnSpec, the null
        // device descriptors, dropped handle, and a helper that finishes the
        // swap by itself after we stop watching. A dead parent pid means the
        // helper does not even need to wait; the bounded poll below only
        // bounds the test, never the helper (its own 60 s budget is the only
        // ceiling — E646).
        $dead = $this->deadChildPid();
        $stage = $this->dir . '/detached.phar.new';
        $target = $this->dir . '/detached.phar';
        \file_put_contents($stage, 'DETACHED-STAGED');
        \file_put_contents($target, 'DETACHED-RUNNING');

        self::assertTrue(WindowsSwap::launch($dead, $stage, $target));

        $deadline = \microtime(true) + 5.0;
        while (\microtime(true) < $deadline) {
            // A rename by another process never refreshes this process's stat
            // cache — poll with the cache cleared, or the loop would watch a
            // ghost and the final assert would fail on a swap that landed.
            \clearstatcache(true, $stage);
            if (!\is_file($stage)) {
                break;
            }
            \usleep(50_000);
        }
        \clearstatcache(true, $stage);
        self::assertFalse(\is_file($stage), 'the detached helper must consume the stage');
        self::assertSame('DETACHED-STAGED', \file_get_contents($target));
        self::assertStringContainsString('swap complete', (string) \file_get_contents($target . '.swap.log'));
    }

    /**
     * Spawn a one-shot child, reap it, and return a pid that is now exited —
     * the parent-pid half of the production contract, honestly dead.
     */
    private function deadChildPid(): int
    {
        $child = \proc_open([\PHP_BINARY, '-r', 'exit(0);'], [], $pipes);
        self::assertIsResource($child);
        $status = \proc_get_status($child);
        $pid = (int) $status['pid'];
        \proc_close($child);

        return $pid;
    }

    /**
     * Run one argv, drain stdout/stderr through pipes, and reap. Drain-then-
     * close (never a single-shot status peek after EOF) so a fast child is
     * judged by its exit code and not by a race against task teardown.
     *
     * @param list<string> $argv
     *
     * @return array{0:int,1:string,2:string}
     */
    private function drainSpawn(array $argv): array
    {
        $child = \proc_open($argv, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($child);
        $out = (string) \stream_get_contents($pipes[1]);
        $err = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $status = \proc_close($child);

        return [$status, $out, $err];
    }
}

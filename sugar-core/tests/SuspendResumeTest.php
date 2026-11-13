<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Msg\ResumeMsg;
use SugarCraft\Core\Msg\SuspendMsg;

/**
 * Suspend family integrity (sc-10, finding sugar-core-014): the stash must
 * reach the model even behind a WithFilter, a no-pcntl build must refuse the
 * whole cycle rather than emit a Resume for a suspend that never happened,
 * and one real stop must deliver AT MOST ONE Resume however many SIGCONTs
 * wake it. Every leg ends in a genuine process stop, so the suite hosts a
 * child Program ({@see /Support/suspend-child.php}) and drives it with real
 * TSTP/CONT from the parent — the only shape that can observe the contract
 * without stopping PHPUnit itself.
 */
final class SuspendResumeTest extends TestCase
{
    private const CHILD = __DIR__ . '/Support/suspend-child.php';

    public function testAFilterPassThroughChildNeverStops(): void
    {
        // Fast structural smoke on the rig itself: the child boots, sees the
        // key, and (new code) never stops when pcntl is switched off, and it
        // sees neither half of the suspend pair.
        $this->skipWithoutPcntlTools();

        [$proc, $toChild, $markers] = $this->startChild(filter: false, noPcntl: true);
        \fwrite($toChild, 'z');
        // Never give it a way to suspend: drive the EOF-quit arm (sc-05) and
        // watch the state across the whole exit so a stop would surface.
        $neverStopped = true;
        $exitCode = $this->finishChild($proc, $toChild, $markers, $neverStopped);

        $seen = $this->markerCounts();
        $this->assertTrue($neverStopped, 'a no-pcntl build cannot stop and must not try');
        $this->assertArrayNotHasKey(SuspendMsg::class, $seen, 'no-pcntl builds dispatch NEITHER suspend msg');
        $this->assertArrayNotHasKey(ResumeMsg::class, $seen, 'no-pcntl builds fake no Resume either');
        $this->assertSame(0, $exitCode);
    }

    public function testPlainChildSeesStashAndExactlyOneResumeThroughARealStop(): void
    {
        $this->skipWithoutPcntlTools();

        [$proc, $toChild, $markers] = $this->startChild(filter: false);
        $this->driveStopAndDoubleCont($proc, $toChild, $markers);

        $seen = $this->markerCounts();
        $this->assertSame(1, $seen[SuspendMsg::class] ?? 0, 'the model stash sees SuspendMsg once');
        $this->assertSame(1, $seen[ResumeMsg::class] ?? 0, 'one stop delivers exactly one Resume through a double SIGCONT');
    }

    public function testFilteredChildStillSeesStashAndExactlyOneResume(): void
    {
        // sugar-core-014's core symptom: with a WithFilter installed the old
        // gate skipped the stash entirely — the app never learned it was
        // being suspended. Runtime bookkeeping is not a filtered key event.
        $this->skipWithoutPcntlTools();

        [$proc, $toChild, $markers] = $this->startChild(filter: true);
        $this->driveStopAndDoubleCont($proc, $toChild, $markers);

        $seen = $this->markerCounts();
        $this->assertSame(1, $seen[SuspendMsg::class] ?? 0, 'the stash reaches the model regardless of the user filter');
        $this->assertSame(1, $seen[ResumeMsg::class] ?? 0, 'and the sole-Resume latch holds behind a filter too');
    }

    // ---------------------------------------------------------------- rig --

    private function skipWithoutPcntlTools(): void
    {
        if (!\function_exists('pcntl_signal') || !\defined('SIGCONT') || !\defined('SIGTSTP')) {
            $this->markTestSkipped('the suspend rig drives real TSTP/CONT and needs pcntl in the parent');
        }
    }

    /**
     * @return array{0: resource, 1: resource, 2: resource} proc, stdin-writer, marker stream
     */
    private function startChild(bool $filter, bool $noPcntl = false): array
    {
        $cmd = [\PHP_BINARY, '-f', self::CHILD, $filter ? 'filter' : 'pass'];
        if ($noPcntl) {
            // A shape only a child can take: every pcntl_* entry point off,
            // so the guard the Program reads sees a no-pcntl build.
            $cmd = [
                \PHP_BINARY,
                '-d',
                'disable_functions=' . implode(',', [
                    'pcntl_signal', 'pcntl_async_signals', 'pcntl_signal_dispatch',
                    'pcntl_signal_get_handler', 'pcntl_alarm', 'pcntl_fork', 'pcntl_wait',
                    'pcntl_waitpid', 'pcntl_exec', 'pcntl_strerror', 'pcntl_get_last_error',
                    'pcntl_getpriority', 'pcntl_setpriority',
                ]),
                '-f',
                self::CHILD,
                $filter ? 'filter' : 'pass',
            ];
        }

        $proc = \proc_open(
            $cmd,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($proc, 'the suspend child must start');
        // Marker stdout first, render stderr second — the harness only ever
        // parses the marker stream; stderr is kept for failure forensics.
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return [$proc, $pipes[0], $pipes[1], $pipes[2]];
    }

    /**
     * Send 'z' (the child turns it into Cmd::suspend()), wait for the REAL
     * stop, wake it with two back-to-back SIGCONTs (the double-CONT shape
     * the latch must collapse to at most one Resume), then collect the run.
     */
    private function driveStopAndDoubleCont($proc, $toChild, $markers): void
    {
        $pid = \proc_get_status($proc)['pid'];
        \fwrite($toChild, 'z');

        $state = $this->awaitProcState($pid, 'T', 30.0);
        $this->assertSame('T', $state, 'the Program must stop the child for real via SIGTSTP');

        \posix_kill($pid, SIGCONT);
        \posix_kill($pid, SIGCONT);
        // Let the child run a good half second alive after the wake before
        // closing it — long enough for any SECOND Resume the latch failed
        // to suppress to surface through the live loop, not just the
        // pre-quit queue drain, even under a loaded host.
        \usleep(500000);
        \fwrite($toChild, 'q');

        $neverStopped = true; // irrelevant here — the stop already proved above
        $exitCode = $this->finishChild($proc, $toChild, $markers, $neverStopped, allowStopped: true);
        $this->assertSame(0, $exitCode, 'the resumed child exits cleanly through Cmd::quit()');
    }

    /**
     * Poll /proc until the child carries the wanted state, or return the
     * last observed state on deadline (the caller asserts, exactly once).
     */
    private function awaitProcState(int $pid, string $want, float $ceilingSeconds): string
    {
        $deadline = \microtime(true) + $ceilingSeconds;
        do {
            $state = $this->procState($pid);
            if ($state === $want || $state === 'gone' || $state === 'Z') {
                return $state;
            }
            \usleep(20000);
        } while (\microtime(true) < $deadline);

        return $this->procState($pid);
    }

    /**
     * Drain markers until the child closes its end, then reap. Polls /proc so
     * a stop at any point is noticed (the no-pcntl legs assert it never
     * happens).
     */
    private function finishChild($proc, $toChild, $markers, bool &$neverStopped, bool $allowStopped = false): int
    {
        // Closing the child's stdin here serves BOTH shapes: the no-pcntl
        // leg needs the EOF-quit (sc-05) to end the run, and the stop legs
        // have already flushed their 'z'. Keeping a write handle open past
        // this point is what strands a child.
        @\fclose($toChild);
        $pid = \proc_get_status($proc)['pid'];
        $buffer = '';
        $deadline = \microtime(true) + 30.0;
        while (\microtime(true) < $deadline) {
            $chunk = \fread($markers, 4096);
            if ($chunk !== '' && $chunk !== false) {
                $buffer .= $chunk;
                // Keep feeding the marker stream out to the child's own timer
                // ceiling instead of blocking the read.
                continue;
            }
            $state = $this->procState($pid);
            if ($state === 'T' && !$allowStopped) {
                $neverStopped = false;
                \posix_kill($pid, SIGCONT);
            }
            if ($state === 'Z' || $state === 'gone') {
                break;
            }
            \usleep(20000);
        }
        // EOF on the marker stream = child done writing.
        $buffer .= (string) \stream_get_contents($markers);

        \fclose($markers);
        $status = \proc_get_status($proc);
        if ($status['running'] ?? false) {
            \proc_terminate($proc, SIGKILL);
        }
        $code = \proc_close($proc); // reaps; also resolves the Z above
        // Rewind a copy so markerCounts can read it: return via property.
        $this->markerBuffer = $buffer;

        return $code;
    }

    private string $markerBuffer = '';

    /** @return array<class-string, int> */
    private function markerCounts(): array
    {
        $counts = [];
        foreach (\explode("\n", $this->markerBuffer) as $line) {
            if (\strncmp($line, 'MSG:', 4) === 0) {
                $class = \substr($line, 4);
                $counts[$class] = ($counts[$class] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /** One char of Linux /proc task state, or 'gone'. */
    private function procState(int $pid): string
    {
        $stat = @\file_get_contents("/proc/$pid/stat");
        if ($stat === false) {
            return 'gone';
        }
        // comm may contain spaces: the state field follows the last ')'.
        $rest = \substr($stat, (int) \strrpos($stat, ')') + 2);

        return \explode(' ', $rest)[0] ?? 'gone';
    }
}

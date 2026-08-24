<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Hooks;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Hooks\BuiltIn\AuditHook;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Support\ForkedChild;

/**
 * WHAT E328 SAID, AND WHY A TEST RATHER THAN A PARAGRAPH SAYS OTHERWISE.
 *
 * E328 recorded the hazard of `AuditHook`'s old shared default leaf as: "Two
 * `sugarcrush` processes on one box append to one file (interleaved, and a
 * partial write from either can split a record)". E344 objected that neither
 * half reproduces. It does not, and this file is the settlement — the claim
 * was FALSE WHEN IT WAS WRITTEN rather than superseded by the fix, because
 * `git show d881f552^` (the commit immediately before E328's fix) already
 * spells `FILE_APPEND | LOCK_EX`. The reachability half of E328 was true and
 * is what the fix closed; see {@see AuditHook::defaultLogFile()}.
 *
 * WHY THIS IS NOT LEFT AS PROSE. Both {@see AuditHook::defaultLogFile()}'s
 * doc-block and the backlog entry now assert "cross-process appending was
 * already safe" as a load-bearing fact — it is the reason a future reader is
 * told NOT to reach for locking. A load-bearing fact stated only in a comment
 * rots the first time somebody changes the write, and the change that would
 * break it (dropping a flag from one `file_put_contents()` call) is a
 * one-token edit that no other test in this tree would notice.
 *
 * THE ANALYSER IS THE THING THAT HAS TO BE TRUSTED, so it is controlled
 * separately from the race. {@see analyse()} is pushed through three
 * synthetic files whose damage is CONSTRUCTED rather than raced for — one
 * missing records, one carrying a truncated record, one carrying a record
 * with a mixed payload — and each must be reported. An "everything intact"
 * verdict from an analyser that cannot report damage is not evidence, and
 * building the controls deterministically rather than by racing the kernel a
 * second time keeps them from being the flaky half of their own proof.
 *
 * The children leave through {@see ForkedChild::exitNow()}, per the
 * convention {@see \SugarCraft\Crush\Tests\Support\ForkedChildExitConventionTest}
 * enforces.
 *
 * @see AuditHook
 */
final class AuditHookConcurrentAppendTest extends TestCase
{
    /**
     * Writers, records each, and payload bytes.
     *
     * The payload is past `PIPE_BUF` (4096 on Linux) and past one page, which
     * is the size class E328's "a partial write can split a record" clause
     * needs to be about anything: a record that fits in one atomic write
     * would prove nothing. It is deliberately far smaller than the round-49
     * scratchpad generator's 8 x 200 — a suite test buys the INVARIANT, and
     * the load figure lives in the backlog entry with its generator.
     */
    private const WRITERS = 4;
    private const RECORDS = 25;
    private const PAYLOAD_BYTES = 9000;

    private string $log = '';

    protected function tearDown(): void
    {
        // Exact-path delete of a name this test created; never a glob.
        if ($this->log !== '' && is_file($this->log)) {
            @unlink($this->log);
        }
        $this->log = '';
    }

    /**
     * A fresh, process-unique log path. `tempnam()` because it is atomic and
     * collision-free by construction — several suites share this machine's
     * temp directory and a composed name is a race of its own (E242).
     */
    private function freshLog(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sc_r49b_audit_');
        self::assertIsString($path, 'could not reserve a temp path for this test');
        $this->log = $path;
        // Reserve the NAME, not an empty first line.
        file_put_contents($path, '');

        return $path;
    }

    /**
     * One record as {@see AuditHook::execute()} spells it, for a given writer
     * letter. Kept beside {@see analyse()} so the two cannot disagree about
     * the shape.
     */
    private static function recordFor(string $letter): string
    {
        return sprintf(
            "[%s] %s %s %s => %s\n",
            date('Y-m-d H:i:s'),
            'sess' . $letter,
            'probe',
            str_repeat($letter, self::PAYLOAD_BYTES),
            str_repeat($letter, 3),
        );
    }

    /**
     * Classify every line of $path as whole / split / interleaved.
     *
     * "Split" is any line that is not a complete, correctly-sized record —
     * the shape a torn write leaves. "Interleaved" is a complete record whose
     * payload is not homogeneous, i.e. two writers' bytes in one line.
     *
     * @return array{lines:int,whole:int,split:int,interleaved:int}
     */
    private static function analyse(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
        $whole = $split = $interleaved = 0;

        foreach ($lines as $line) {
            $matched = preg_match('/^\[[0-9 :-]+\] sess([A-Z]) probe ([A-Z]+) => ([A-Z]{3})$/', $line, $m) === 1;
            if (!$matched || strlen($m[2]) !== self::PAYLOAD_BYTES) {
                $split++;
                continue;
            }
            if ($m[2] !== str_repeat($m[1], self::PAYLOAD_BYTES) || $m[3] !== str_repeat($m[1], 3)) {
                $interleaved++;
                continue;
            }
            $whole++;
        }

        return [
            'lines' => count($lines),
            'whole' => $whole,
            'split' => $split,
            'interleaved' => $interleaved,
        ];
    }

    /**
     * THE CLAIM. Concurrent writers through the real hook lose nothing, split
     * nothing and interleave nothing.
     */
    public function testConcurrentAppendsThroughTheRealHookAreNeitherLostNorSplit(): void
    {
        self::assertTrue(
            function_exists('pcntl_fork') && function_exists('pcntl_waitpid'),
            'this host has no pcntl, so the claim this file exists to pin cannot be measured here',
        );

        $log = $this->freshLog();
        $expected = self::WRITERS * self::RECORDS;

        $pids = [];
        for ($w = 0; $w < self::WRITERS; $w++) {
            $pid = pcntl_fork();
            self::assertNotSame(-1, $pid, 'fork failed');
            if ($pid === 0) {
                // A CALLER-SUPPLIED path, so `$ownsPath` is false and the write
                // is byte-for-byte the pre-E328 one: no directory guard, no
                // symlink refusal, no umask narrowing, just the append.
                $hook = new AuditHook($log);
                $letter = chr(ord('A') + $w);
                for ($r = 0; $r < self::RECORDS; $r++) {
                    $hook->execute(new HookContext(
                        sessionId: 'sess' . $letter,
                        toolName: 'probe',
                        toolArgs: [],
                        toolInput: str_repeat($letter, self::PAYLOAD_BYTES),
                        toolOutput: str_repeat($letter, 3),
                        model: 'probe-model',
                        provider: 'probe',
                        projectRoot: '/',
                    ));
                }
                ForkedChild::exitNow(0);
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $seen = self::analyse($log);

        self::assertSame($expected, $seen['lines'], 'records were LOST — FILE_APPEND is not holding');
        self::assertSame(0, $seen['split'], 'a record was TORN — LOCK_EX is not holding');
        self::assertSame(0, $seen['interleaved'], 'two writers landed in one record');
        self::assertSame($expected, $seen['whole']);
    }

    /**
     * KNOWN-POSITIVE CONTROL 1 — the analyser reports LOSS.
     *
     * Constructed, not raced: a file holding one record where the run above
     * would have produced every writer's. This is the damage `FILE_APPEND`
     * prevents, and without this control the `assertSame($expected, …)` above
     * would be equally satisfied by an analyser that always answered with the
     * number it was asked for.
     */
    public function testTheAnalyserReportsLoss(): void
    {
        $log = $this->freshLog();
        file_put_contents($log, self::recordFor('A'));

        $seen = self::analyse($log);

        self::assertSame(1, $seen['lines']);
        self::assertNotSame(self::WRITERS * self::RECORDS, $seen['lines']);
        self::assertSame(1, $seen['whole']);
    }

    /**
     * KNOWN-POSITIVE CONTROL 2 — the analyser reports a SPLIT record.
     *
     * A whole record, then a torn one, then a whole one: exactly the shape a
     * non-atomic write leaves behind, and the shape E328's second clause
     * claimed to see. The `split` count above is worthless unless this fires.
     */
    public function testTheAnalyserReportsASplitRecord(): void
    {
        $log = $this->freshLog();
        $torn = substr(self::recordFor('B'), 0, 2000) . "\n";
        file_put_contents($log, self::recordFor('A') . $torn . self::recordFor('C'));

        $seen = self::analyse($log);

        self::assertSame(3, $seen['lines']);
        self::assertSame(1, $seen['split']);
        self::assertSame(2, $seen['whole']);
    }

    /**
     * KNOWN-POSITIVE CONTROL 3 — the analyser reports an INTERLEAVED record.
     *
     * A correctly-shaped, correctly-sized record whose payload carries two
     * writers' bytes. This is the arm a length check alone cannot see, and it
     * is the one E328's word "interleaved" names.
     */
    public function testTheAnalyserReportsAnInterleavedRecord(): void
    {
        $log = $this->freshLog();
        $mixed = sprintf(
            "[%s] %s %s %s => %s\n",
            date('Y-m-d H:i:s'),
            'sessA',
            'probe',
            str_repeat('A', self::PAYLOAD_BYTES - 500) . str_repeat('D', 500),
            'AAA',
        );
        file_put_contents($log, self::recordFor('A') . $mixed);

        $seen = self::analyse($log);

        self::assertSame(2, $seen['lines']);
        self::assertSame(0, $seen['split'], 'the mixed record is the right SIZE; it must not be scored as torn');
        self::assertSame(1, $seen['interleaved']);
        self::assertSame(1, $seen['whole']);
    }
}

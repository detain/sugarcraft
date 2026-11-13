<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * CODE-TEST-02: no live wall-clock COST budget in the default run.
 *
 * Two shapes of clock assertion exist in this suite and only one is a flake
 * class. A generous BOUND ("the call returned within 10 s, so the timeout
 * fired and nothing hung") asserts behaviour and is the hang-catcher the suite
 * wants. A COST ceiling — micro-/milliseconds per operation, or any sub-second
 * ceiling on a clock-derived value — asserts how fast the host is, and a
 * loaded shared runner trades green for noise on it.
 *
 * This census reads every test file for the cost shape and refuses it unless
 * the file is rostered AND each hit sits behind the explicit bench gate
 * (`CANDY_CODE_PERF_BENCH=1`). The default run therefore carries none; the
 * bounded ones keep their generous multi-second ceilings. Adding a cost
 * ceiling means gating it, injecting a clock, or asserting the deterministic
 * observable (memo hit, op count) instead.
 *
 * ROSTER, 2026-11-13: `Media/PerfBudgetTest.php` — every `$perOp` ceiling in it
 * is behind the bench gate.
 */
final class WallClockBudgetCensusTest extends TestCase
{
    /** @var list<string> tests/-relative paths allowed to carry bench-gated cost ceilings */
    private const BENCH_GATED_ROSTER = ['Media/PerfBudgetTest.php'];

    /** The cost shape: a numeric literal bound against a per-op/ms variable or a sub-second clock delta. */
    private const COST = '/assertLessThan(?:OrEqual)?\(\s*(?:(?<lit>[0-9][0-9_.]*)\s*,\s*(?<var>\$perOp|\$ms\b|\(microtime))/';

    public function testNoCostCeilingRunsInTheDefaultSuite(): void
    {
        $offenders = [];
        foreach ($this->hits() as [$path, $line, $above]) {
            if (!in_array($path, self::BENCH_GATED_ROSTER, true)) {
                $offenders[] = "$path:$line is a wall-clock cost ceiling outside the roster";
                continue;
            }
            if (!str_contains($above, '$bench') && !str_contains($above, 'CANDY_CODE_PERF_BENCH')) {
                $offenders[] = "$path:$line is rostered but not behind the CANDY_CODE_PERF_BENCH gate";
            }
        }

        $this->assertSame([], $offenders, "CODE-TEST-02:\n" . implode("\n", $offenders));
    }

    public function testTheRosterStillHasAReasonToExist(): void
    {
        $seen = [];
        foreach ($this->hits() as [$path]) {
            $seen[$path] = true;
        }
        foreach (self::BENCH_GATED_ROSTER as $row) {
            $this->assertArrayHasKey($row, $seen, "$row no longer carries a cost ceiling — delete its roster row");
        }
    }

    /**
     * @return \Generator<int, array{0: string, 1: int, 2: string}> path, line, the 8 lines above
     */
    private function hits(): \Generator
    {
        $root = dirname(__DIR__);
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php' || $file->getPathname() === __FILE__) {
                continue;
            }
            $lines = file($file->getPathname(), FILE_IGNORE_NEW_LINES) ?: [];
            foreach ($lines as $i => $text) {
                $trim = ltrim($text);
                if (str_starts_with($trim, '*') || str_starts_with($trim, '//') || !preg_match(self::COST, $text)) {
                    continue;
                }
                yield [substr($file->getPathname(), strlen($root) + 1), $i + 1, implode("\n", array_slice($lines, max(0, $i - 8), 8))];
            }
        }
    }
}

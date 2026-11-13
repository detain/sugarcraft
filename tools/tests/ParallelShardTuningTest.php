<?php

declare(strict_types=1);

namespace SugarCraft\Tools\Tests;

require_once __DIR__ . '/_bootstrap.php';

use PHPUnit\Framework\TestCase;

/**
 * RT-26: the E671/E691 shard-tuning comments in `.github/workflows/ci.yml`
 * quoted 2026-09-era baselines (~560s serial, ~443s K=4 buckets at 21,147
 * tests) long after the suite had doubled them. That class of staleness is
 * worse than a bare missing number: a contributor reads "1200 restores ~2x
 * headroom", does the arithmetic against the quoted 443, and concludes a
 * timeout change is safe when the real bucket is 798 and the headroom is 1.5x.
 * A stale constant is a LIE WITH PRECISION.
 *
 * THE FIX THIS GUARD ENFORCES: the comments carry machine-readable pin lines —
 * `TSV-PIN` in the test job and `TUNING-REF` in the coverage job — and every
 * value on them is RE-DERIVED here from the two committed sources of truth:
 * `scripts/parallel-tests-durations.tsv` (row count, serial sum, floor file,
 * LPT K=4 max bucket) and `candy-code/tests/Config/Support/suite-figure.json`
 * (the tests headline the conservation gate already pins). This is the same
 * docs-pinned-by-tests shape this repo enforces for the candy-code README
 * roster — prose that must answer to its generator.
 *
 * WHY THIS LANE READS THE TRIO AND WRITES NOTHING. The durations table and the
 * figure are welder-owned (frozen trio); the RT-26 owner gate covers changing
 * the SHARDING, and this file changes only the WORDS — it derives from the
 * committed bytes exactly as ci.yml's comments must, and the read-only rule is
 * asserted below with an md5 pin so a future edit to this very test cannot
 * grow a write into the trio unnoticed.
 *
 * THE RELATIONSHIP ASSERTIONS ARE THE OTHER HALF. Comment-vs-derived equality
 * alone would go green on `timeout=0` if the derived bucket were also 0;
 * instead the headroom law (timeout clears the max bucket; coverage timeout
 * clears pcov_multiple x bucket) is asserted against the numbers themselves,
 * and the both-directions mutation proof (perturb the table copy, perturb the
 * comment copy) shows each comparison can actually fail.
 *
 * @internal
 */
final class ParallelShardTuningTest extends TestCase
{
    private const TSV = '/scripts/parallel-tests-durations.tsv';
    private const FIGURE = '/candy-code/tests/Config/Support/suite-figure.json';
    private const CI = '/.github/workflows/ci.yml';

    /** The K the workflow caps at (`if [ "$K" -gt 4 ]; then K=4`). */
    private const K = 4;

    /**
     * Rows of the durations table: file => seconds, in committed order.
     *
     * @return array<string, float>
     */
    private static function durations(string $tsvText): array
    {
        $rows = [];
        foreach (\explode("\n", $tsvText) as $line) {
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $parts = \explode("\t", $line);
            if (\count($parts) < 2) {
                continue;
            }
            $rows[$parts[0]] = (float) $parts[1];
        }

        return $rows;
    }

    /**
     * LPT (longest-processing-time-first) max bucket for K shards.
     *
     * @param array<string, float> $durations
     */
    private static function lptMaxBucket(array $durations, int $k): float
    {
        $sorted = \array_values($durations);
        \rsort($sorted);
        $buckets = \array_fill(0, $k, 0.0);
        foreach ($sorted as $seconds) {
            $smallest = 0;
            for ($i = 1; $i < $k; $i++) {
                if ($buckets[$i] < $buckets[$smallest]) {
                    $smallest = $i;
                }
            }
            $buckets[$smallest] += $seconds;
        }

        return \max($buckets);
    }

    /**
     * The derived answer for every TSV-PIN / TUNING-REF key.
     *
     * @return array<string, string>
     */
    private static function derive(string $tsvText, string $figureJson): array
    {
        $durations = self::durations($tsvText);
        $figure = \json_decode($figureJson, true, 512, JSON_THROW_ON_ERROR);
        \ksort($durations);
        $floor = \max($durations);
        $floorFile = (string) \array_search($floor, $durations, true);
        $pins = [
            'tests' => (string) $figure['tests'],
            'files' => (string) \count($durations),
            'serial_sum' => \number_format(\array_sum($durations), 1, '.', ''),
            'floor' => \number_format($floor, 1, '.', ''),
            'floor_file' => $floorFile,
            'lpt_k4_max' => \number_format(self::lptMaxBucket($durations, self::K), 1, '.', ''),
        ];
        return $pins;
    }

    /**
     * Every `# TSV-PIN k=v ...` / `# TUNING-REF k=v ...` pair quoted in ci.yml.
     *
     * @return array<string, string>
     */
    private static function quotedPins(string $ciText): array
    {
        $pins = [];
        \preg_match_all('/^ *# (?:TSV-PIN|TUNING-REF) (.+)$/m', $ciText, $all);
        foreach ($all[1] as $row) {
            foreach (\explode(' ', \trim($row)) as $pair) {
                [$k, $v] = \explode('=', $pair, 2);
                $pins[$k] = $v;
            }
        }

        return $pins;
    }

    public function testTheCiCommentPinsMatchWhatTheCommittedTableAndFigureDerive(): void
    {
        $root = \dirname(__DIR__, 2);
        $tsvPath = $root . self::TSV;
        $tsvBefore = \md5_file($tsvPath);

        $pins = self::quotedPins((string) \file_get_contents($root . self::CI));
        $derived = self::derive(
            (string) \file_get_contents($tsvPath),
            (string) \file_get_contents($root . self::FIGURE),
        );
        // The timeout/pcov keys are quoting-only; compare the derived side and
        // check the quoting-only side by relationship below.
        $quotedDerived = \array_intersect_key($pins, $derived);
        $this->assertGreaterThanOrEqual(
            6,
            \count($quotedDerived),
            'the TSV-PIN line lost keys — fewer than 6 comparable pins means this guard is '
            . 'scoring an empty comparison, not a clean one',
        );
        foreach ($quotedDerived as $key => $value) {
            $this->assertSame(
                $derived[$key],
                $value,
                "ci.yml quotes `$key=$value` but the committed durations table and suite figure "
                . "derive `$key={$derived[$key]}`. Re-measure deliberately or accept the new "
                . 'numbers — never delete this guard to pass it',
            );
        }
        $this->assertSame(
            $tsvBefore,
            \md5_file($tsvPath),
            'this test wrote to the frozen durations trio — read-only by law',
        );
    }

    public function testTheTimeoutsStillClearWhatTheTableDerives(): void
    {
        $root = \dirname(__DIR__, 2);
        $ci = (string) \file_get_contents($root . self::CI);
        $pins = self::quotedPins($ci);
        $derived = self::derive(
            (string) \file_get_contents($root . self::TSV),
            (string) \file_get_contents($root . self::FIGURE),
        );
        $bucket = (float) $derived['lpt_k4_max'];
        $this->assertGreaterThan(0.0, $bucket, 'a zero max bucket makes every comparison below vacuous');

        // The step invocations the pins claim to describe.
        $this->assertSame(
            1,
            \preg_match('/--timeout (\d+) \|\| RC/', $ci, $m1),
            'the test-job step no longer ends its shard run with `--timeout N || RC` — the pin '
            . 'cannot vouch for a step shape it cannot find',
        );
        $this->assertSame(
            1,
            \preg_match('/--timeout (\d+) --clover/', $ci, $m2),
            'the coverage-job step no longer passes `--timeout N --clover`',
        );
        [$testTimeout, $coverageTimeout] = [$m1[1], $m2[1]];
        $this->assertSame((int) $pins['timeout_test'], (int) $testTimeout, 'the TSV-PIN timeout_test row no longer matches the step that runs');
        $this->assertGreaterThanOrEqual(
            $bucket * 1.2,
            (float) $testTimeout,
            '--timeout no longer clears the LPT K=4 max bucket with headroom — rebalance the '
            . 'shards or raise the pin deliberately, but do not let the comment lie',
        );
        $this->assertLessThan(
            (float) $derived['serial_sum'],
            (float) $testTimeout,
            'the shard timeout now exceeds the serial wall — sharding has stopped buying anything',
        );
        $this->assertGreaterThanOrEqual(
            $bucket * (float) $pins['pcov_multiple'],
            (float) $coverageTimeout,
            'coverage --timeout no longer clears pcov_multiple x max bucket',
        );
    }

    public function testTheGuardFailsWhenTheTableMovesUnderAStaleQuote(): void
    {
        $root = \dirname(__DIR__, 2);
        $committed = self::derive(
            (string) \file_get_contents($root . self::TSV),
            (string) \file_get_contents($root . self::FIGURE),
        );
        $pins = self::quotedPins((string) \file_get_contents($root . self::CI));

        // Rule 25 in the other direction: this is what the comparison above
        // would answer if a trio weld re-measured KeyHelpTest downward without
        // the comment riding along. A perturbation of a COPY of the table —
        // the committed bytes are never touched.
        $mutated = \preg_replace(
            '/^candy-code\/tests\/Renderer\/KeyHelpTest\.php\t[\d.]+/m',
            'candy-code/tests/Renderer/KeyHelpTest.php	201.0',
            (string) \file_get_contents($root . self::TSV),
            1,
            $count,
        );
        $this->assertSame(1, $count, 'the floor row of the table moved shape — the mutation arm cannot prove anything');
        $after = self::derive((string) $mutated, (string) \file_get_contents($root . self::FIGURE));
        $this->assertNotSame($committed['lpt_k4_max'], $after['lpt_k4_max'], 'derivation is insensitive to the floor row');
        $this->assertNotSame($committed['floor'], $after['floor']);
        $this->assertSame($pins['lpt_k4_max'], $committed['lpt_k4_max']);
        // ... and therefore the live equality arm would go red: the quoted pin
        // no longer equals the re-derived value. Assert the inequality the
        // suite would enforce, here, from fixture.
        $this->assertNotSame($pins['lpt_k4_max'], $after['lpt_k4_max']);

        // And a perturbed COMMENT copy fails against the UNperturbed table:
        $staleQuote = \str_replace('lpt_k4_max=798.1', 'lpt_k4_max=443.0', (string) \file_get_contents($root . self::CI));
        $pinsNow = self::quotedPins($staleQuote);
        $this->assertNotSame($committed['lpt_k4_max'], $pinsNow['lpt_k4_max'], 'a 2026-09 number pasted back would go unnoticed');
    }

    public function testTheFloorLawIsSpelledInTheComment(): void
    {
        $ci = (string) \file_get_contents(\dirname(__DIR__, 2) . self::CI);
        $this->assertMatchesRegularExpression(
            '/#.*THE WALL IS FLOOR-BOUND/',
            $ci,
            'the floor-bound law sentence was dropped from the shard comments — the reason any '
            . 'K=4 rebalance can still miss (the max bucket never goes under the slowest file)',
        );
    }
}

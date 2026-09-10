<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Config;

use PHPUnit\Framework\TestCase;

/**
 * THE README HEADLINE FIGURE IS PINNED, AND THE PIN HAS TWO HALVES OF TWO
 * DIFFERENT KINDS — which is the decision this file exists to document (E669).
 *
 * `README.md` headlines the suite as "N tests / M assertions, F failures, S
 * skipped". Rounds 44-62 shipped that figure three times and every one of them
 * was stale on arrival — the last was behind the suite it was written against
 * the day it landed (the README itself confessed this and rested on "the
 * command above is the authority", which is true and still lets the figure
 * rot). This guard makes the two halves of the figure behave differently,
 * because deriving them costs wildly different amounts:
 *
 * TESTS — re-derivable LIVE in about three seconds. `phpunit --list-tests`
 * enumerates every collected test WITHOUT running any of it (the same
 * mechanism E634 measured with), and its line count is the number the README
 * spells. The child run is cheap, deterministic, and already precedented in
 * this tree ({@see \SugarCraft\Crush\Tests\Support\RequirementDirectiveProvenanceTest}
 * launches a child phpunit with `timeout -s KILL` + `--log-junit` the same
 * way). So the tests figure is a HARD live pin: adding a test method without
 * updating the README reddens {@see testReadmeHeadlineTestsFigureMatchesTheLiveEnumeration}
 * on the next run of anything.
 *
 * ASSERTIONS — not re-derivable without the full ~8-minute run, and a guard
 * that re-runs the suite inside the suite is self-referential: the run it
 * would fail inside of is the run whose total it checks, it doubles CI cost
 * unboundedly (the child would want its own guard), and it cannot see its own
 * assertion count anyway. THAT is the rule-7 reason for the second shape: the
 * assertions figure is a MAINTAINED ARTIFACT —
 * `tests/Config/Support/suite-figure.json`, regenerated from a real run's
 * JUnit log by `tests/Config/Support/refresh-suite-figure.php` — and it is
 * kept honest by a LOUD STALENESS GUARD instead of a silent promise:
 * {@see testArtifactIsNotStaleAgainstTheLiveEnumeration} cross-checks the
 * artifact's tests figure against the same live enumeration. The moment a test
 * arrives without a regeneration, BOTH the README pin and the artifact pin go
 * red, and the documented remedy (a full run + the refresh script) re-derives
 * the assertions figure at the same time. The failure is never silent — that
 * is the whole design: staleness is converted from a slow rot into a red test.
 *
 * VACUITY DISCIPLINE. Every arm fails loud rather than passing on absence:
 * the README pattern is asserted to match before it is compared (a reworded
 * headline is a failure, not a skip); the child run's exit status and a
 * 10,000-test floor are checked before its count is trusted, so a crashed or
 * partially-enumerated child cannot match a lowball README; and a missing or
 * malformed artifact is a failure. If the suite ever legitimately drops below
 * the floor, the floor itself is the thing that must be re-measured — loudly.
 *
 * @internal
 */
final class ReadmeSuiteFigureDriftTest extends TestCase
{
    /**
     * Wall-clock budget for the enumeration child. It takes ~3s here; the cap
     * is bound by {@see \SugarCraft\Crush\Tests\Support\ChildWallClockBudgetTest}
     * to leave the parent's 60s defaultTimeLimit room to lose — this census
     * reddened 120 on the first full run, which is the guard working.
     */
    private const CHILD_WALL_CLOCK_BUDGET_SECONDS = 40;

    /**
     * Floor under which an enumeration result is treated as a crash, not a
     * measurement. MEASURED live at this commit via `phpunit --list-tests`
     * (see the artifact); this is not the suite total, it is a sanity floor.
     */
    private const LIVE_ENUMERATION_FLOOR = 10_000;

    private const ARTIFACT = __DIR__ . '/Support/suite-figure.json';

    /** One child run serves both arms. */
    private static ?int $liveTestCount = null;

    public function testReadmeHeadlineTestsFigureMatchesTheLiveEnumeration(): void
    {
        $headline = $this->parseReadmeHeadline();

        self::assertSame(
            self::liveTestCount(),
            $headline['tests'],
            'the README headline states a tests figure that the live `--list-tests` '
            . 'enumeration does not. Update the headline; the assertions figure in the same '
            . 'line is maintained through tests/Config/Support/suite-figure.json.',
        );
    }

    public function testReadmeHeadlineAgreesWithTheMaintainedArtifact(): void
    {
        $headline = $this->parseReadmeHeadline();
        $artifact = $this->readArtifact();

        self::assertSame(
            [$artifact['tests'], $artifact['assertions'], $artifact['failures'], $artifact['skipped']],
            [$headline['tests'], $headline['assertions'], $headline['failures'], $headline['skipped']],
            'the README headline and the maintained artifact have drifted apart — the headline '
            . 'was edited without the artifact (or the other way), which is exactly the pair '
            . 'this guard exists to keep welded. Regenerate both from one real run.',
        );
    }

    public function testArtifactIsNotStaleAgainstTheLiveEnumeration(): void
    {
        $artifact = $this->readArtifact();

        self::assertSame(
            self::liveTestCount(),
            $artifact['tests'],
            'the maintained artifact predates the current test set: it claims '
            . $artifact['tests'] . ' tests and the live enumeration collects '
            . self::liveTestCount() . '. Re-run the suite with --log-junit and '
            . '`php tests/Config/Support/refresh-suite-figure.php <junit.xml>` from '
            . 'sugar-crush/, then update the README headline from the same run. Until then '
            . 'the assertions figure in the artifact is by construction older than this '
            . 'message says, which is the rot the pair used to carry silently.',
        );
    }

    /**
     * The headline is one exact sentence; a rewording must update this pattern
     * deliberately rather than un-pin the figure by accident.
     *
     * @return array{tests: int, assertions: int, failures: int, skipped: int}
     */
    private function parseReadmeHeadline(): array
    {
        $readme = (string) file_get_contents(\dirname(__DIR__, 2) . '/README.md');

        $matched = preg_match(
            '/\*\*([\d,]+) tests \/ ([\d,]+) assertions, ([\d,]+) failures, ([\d,]+) skipped\*\*/',
            $readme,
            $m,
        );

        self::assertSame(
            1,
            $matched,
            'README.md no longer carries the pinned headline sentence patterned below. '
            . 'The figure may be reworded, but it may not silently '
            . 'escape the pin — re-point this test at the new spelling.',
        );

        return [
            'tests' => (int) str_replace(',', '', $m[1]),
            'assertions' => (int) str_replace(',', '', $m[2]),
            'failures' => (int) str_replace(',', '', $m[3]),
            'skipped' => (int) str_replace(',', '', $m[4]),
        ];
    }

    /** @return array{tests: int, assertions: int, failures: int, skipped: int, measured_at: string, php: string, command: string} */
    private function readArtifact(): array
    {
        self::assertFileExists(self::ARTIFACT, 'the maintained suite-figure artifact is missing');

        $decoded = json_decode((string) file_get_contents(self::ARTIFACT), true, 512, JSON_THROW_ON_ERROR);

        foreach (['tests', 'assertions', 'failures', 'skipped'] as $key) {
            self::assertIsInt($decoded[$key] ?? null, "artifact key {$key} is missing or not an int");
        }

        self::assertIsString($decoded['measured_at'] ?? null);
        self::assertIsString($decoded['command'] ?? null);

        return $decoded;
    }

    private static function liveTestCount(): int
    {
        if (self::$liveTestCount !== null) {
            return self::$liveTestCount;
        }

        $root = \dirname(__DIR__, 2);
        $status = 0;
        $output = [];

        exec(\sprintf(
            'cd %s && timeout -s KILL %d %s %s --list-tests -c %s 2>/dev/null',
            \escapeshellarg($root),
            self::CHILD_WALL_CLOCK_BUDGET_SECONDS,
            \escapeshellarg(\PHP_BINARY),
            \escapeshellarg($root . '/vendor/bin/phpunit'),
            \escapeshellarg($root . '/phpunit.xml'),
        ), $output, $status);

        self::assertSame(0, $status, 'the `--list-tests` child exited ' . $status
            . ' — the live enumeration half of this guard read nothing at all');

        $count = 0;
        foreach ($output as $line) {
            if (str_starts_with($line, ' - ')) {
                ++$count;
            }
        }

        self::assertGreaterThan(
            self::LIVE_ENUMERATION_FLOOR,
            $count,
            'the live enumeration collected only ' . $count . ' tests, below the sanity floor — '
            . 'treat as a crashed/partial run, not a figure. If the suite really shrank below '
            . self::LIVE_ENUMERATION_FLOOR . ', this floor is the stale claim to re-measure.',
        );

        return self::$liveTestCount = $count;
    }
}

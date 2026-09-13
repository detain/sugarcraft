<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

/**
 * ONE BOUNDED CHILD RUN FOR THE PROVENANCE SUITES (E390).
 *
 * WHY A TRAIT, AND WHY THIS SHAPE. Two suites each run real child PHP
 * processes during their tests: `Cli/BootstrapSkillSkipsTest` launches the
 * CLI and one-shots the binary, and {@see \SugarCraft\Crush\Tests\Support\RequirementDirectiveProvenanceTest}
 * launches a whole second `phpunit`. Both wrap the child in `timeout(1)` with
 * `-s KILL` and a wall-clock budget, and both refuse the kill before reading
 * anything the child left behind. The budget constant was declared twice,
 * byte-identically, under a licensé row in
 * {@see \SugarCraft\Crush\Tests\Support\DuplicatedTestHelperDriftTest} — and
 * it could not simply be hoisted, because the census in
 * {@see \SugarCraft\Crush\Tests\Support\ChildWallClockBudgetTest} reduces a
 * `self::` budget against the token stream of the ONE file that spells the
 * wrapper. A constant moved out on its own would leave every wrapper behind
 * naming a constant its file no longer declares, and the census — which
 * reports what it cannot reduce rather than certifying it absent — would red.
 * So the wrapper comes to the constant. This file spells the one
 * `timeout -s KILL %d` the two suites share, declares the budget beside it,
 * and both suites call the runner. E390's one motion: consolidate the pair,
 * drop the licence, keep the same-file law unbroken.
 *
 * WHAT STAYS PER-SUITE ON PURPOSE: the assertions on the returned status.
 * `assertTheChildRanToCompletion()` in BootstrapSkillSkipsTest refuses every
 * non-zero exit because its tests assert an ABSENCE from stderr, and a child
 * that never ran looks exactly like a child with nothing to say;
 * RequirementDirectiveProvenanceTest bounds the kill and then reads the JUnit
 * log its child wrote, which is a different claim about the same process.
 * Two sentences wearing one helper would have to be the weaker of the two.
 *
 * @internal
 */
trait RunsWallClockBoundedChildTrait
{
    /**
     * THE WALL-CLOCK BUDGET FOR THE REAL CHILD PROCESSES THESE SUITES RUN,
     * AND IT IS A CONSTANT SO THE NEXT READER CAN RAISE IT KNOWINGLY.
     *
     * WHY IT IS NOT SIXTY, WHICH IS WHAT IT WAS. `phpunit.xml` sets
     * `enforceTimeLimit` with a per-test limit of its own, and PHPUnit
     * enforces that with `pcntl_alarm()` plus a handler that throws. A budget
     * EQUAL to that limit means the two clocks expire together and PHPUnit's
     * wins, since its clock starts before `setUp()` and the child's starts
     * after. When it wins, the test is ABORTED rather than failed — and
     * because `phpunit.xml` also sets `failOnRisky`, an abort here IS a red
     * (measured on this host, PHP 8.3.6 / PHPUnit 10.5.64, two
     * configurations differing ONLY in that attribute and one test whose
     * child outlives the limit: rc 1 against rc 0, same banner). What losing
     * the race still costs is the assertions the specific arm would have
     * made and a diagnosis that names no child — trading a specific red for
     * a generic one is a loss worth one constant.
     *
     * THE MEASUREMENTS UNDER THE BUDGET, each at load average 6 with sibling
     * suites running: one `-p` CLI child costs 0.28s (three takes: 0.28 /
     * 0.28 / 0.28) and BootstrapSkillSkipsTest's whole file runs in 0.41s —
     * ~70x the thing it bounds; the fixture child phpunit costs 0.079s —
     * ~250x. A child that genuinely hangs is SIGKILLed first and reported
     * by the suite's own arm, not by a generic abort.
     *
     * RAISING IT IS A REAL TRADE AND IT HAS A CEILING.
     * {@see \SugarCraft\Crush\Tests\Cli\BootstrapSkillSkipsTest::testTheChildBudgetStaysUnderThePerTestLimitPhpunitEnforces()}
     * pins the budget against `phpunit.xml` so the relation cannot rot
     * silently, and
     * {@see \SugarCraft\Crush\Tests\Cli\BootstrapSkillSkipsTest::testAnAbortAtThatCeilingStillFailsTheRun()}
     * pins the attribute that decides what an abort costs. The headroom
     * policy the first guard compares against — the child may never be given
     * more than half the per-test clock — lives with that guard in
     * `Cli/BootstrapSkillSkipsTest.php`.
     */
    private const CHILD_WALL_CLOCK_BUDGET_SECONDS = 20;

    /**
     * What a shell reports when `timeout -s KILL` kills the child: 128 + SIGKILL.
     */
    private const KILLED_BY_THE_BUDGET = 137;

    /**
     * Run one real child, wall-clock bounded, through the shell.
     *
     * The command arrives in two pieces because the shell needs the
     * environment ahead of the wrapper and the child after it: `$shellPrefix`
     * carries whatever must run BEFORE `timeout` (`cd … && HOME=…`), and
     * `$command` is the child itself with its redirects — the merged `2>&1`
     * capture or a `>/dev/null 2>file` split is the caller's choice, spelled
     * inside `$command`. Pass an empty prefix for a bare child.
     *
     * THIS IS THE FILE THE WRAPPER LIVES IN, and that is load-bearing, not
     * tidiness: {@see \SugarCraft\Crush\Tests\Support\ChildWallClockBudgetTest}
     * resolves the `%d` below against this file's own integer literals, so
     * the wrapper and its budget stay same-file together (see the
     * class-doc-block above for what the alternative cost E390).
     *
     * @return array{0: int, 1: list<string>} the exit status, and the lines
     *                                        the child wrote where its
     *                                        redirects pointed
     */
    private function runWallClockBoundedChild(string $shellPrefix, string $command): array
    {
        $status = 0;
        $output = [];
        exec(\sprintf(
            '%s timeout -s KILL %d %s',
            $shellPrefix,
            self::CHILD_WALL_CLOCK_BUDGET_SECONDS,
            $command,
        ), $output, $status);

        return [$status, $output];
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Testing;

use PHPUnit\Framework\Assert;

/**
 * The result of a {@see ProgramSimulator::run()} call.
 *
 * Captures the final model state, the last and per-step view() frames,
 * emitted commands, the accumulated output stream, and how many queued
 * messages were dropped because a QuitMsg/InterruptMsg ended the drain
 * early — so a test can pin "the quit actually stopped things" instead of
 * trusting an empty-looking run.
 *
 * Upstream-antecedented (charmbracelet/teatest TestResult) but first-party
 * hardened — see docs/MATCHUPS.md.
 */
final readonly class TestResult
{
    /**
     * @param object         $model            Final model after all messages processed
     * @param string         $view             Last view() output string
     * @param list<\Closure> $cmds             All commands emitted during the run
     * @param string         $output           Concatenated view() output across
     *                                         steps, plus verbatim RawMsg and
     *                                         PrintMsg side-channel writes
     * @param list<string>   $frames           view() after init-trio updates, each
     *                                         update leg, and the final render —
     *                                         every frame the run produced, in order
     * @param int            $droppedAfterStop Messages skipped because the drain hit
     *                                         a QuitMsg/InterruptMsg (parity with
     *                                         Program stopping its loop)
     */
    public function __construct(
        public object $model,
        public string $view,
        public array $cmds,
        public string $output,
        public array $frames = [],
        public int $droppedAfterStop = 0,
    ) {}

    /**
     * Assert the exact number of commands was emitted.
     *
     * Routes through PHPUnit's {@see Assert} so a mismatch raises an
     * {@see \PHPUnit\Framework\AssertionFailedError} (counted as an
     * assertion) rather than a raw \RuntimeException that PHPUnit would
     * report as an error.
     *
     * @param int $expected Expected command count
     * @throws \PHPUnit\Framework\AssertionFailedError if count does not match
     */
    public function assertCmdCount(int $expected): void
    {
        Assert::assertCount(
            $expected,
            $this->cmds,
            \sprintf('Expected %d commands, but got %d.', $expected, \count($this->cmds)),
        );
    }

    /**
     * Assert that at least one command matches the given filter.
     *
     * @param callable(\Closure): bool $filter  Returns true for matching cmd
     * @param string|null              $message Failure context — without it the
     *                                          report says only how many cmds were
     *                                          captured, which is rarely actionable
     * @throws \PHPUnit\Framework\AssertionFailedError if no cmd matches
     */
    public function assertCmdContains(callable $filter, ?string $message = null): void
    {
        foreach ($this->cmds as $cmd) {
            if ($filter($cmd) === true) {
                Assert::assertTrue(true);
                return;
            }
        }

        Assert::fail(sprintf(
            'No command matched the given filter (out of %d captured command(s)).%s',
            count($this->cmds),
            $message === null ? '' : ' ' . $message,
        ));
    }

    /**
     * Assert that no commands were emitted.
     *
     * @throws \PHPUnit\Framework\AssertionFailedError if any commands were emitted
     */
    public function assertNoCmds(): void
    {
        Assert::assertCount(
            0,
            $this->cmds,
            \sprintf('Expected no commands, but got %d.', \count($this->cmds)),
        );
    }
}

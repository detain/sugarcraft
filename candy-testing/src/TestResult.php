<?php

declare(strict_types=1);

namespace SugarCraft\Testing;

use PHPUnit\Framework\Assert;

/**
 * The result of a {@see ProgramSimulator::run()} call.
 *
 * Captures the final model state, accumulated view bytes, emitted commands,
 * and the concatenated view() output across steps for golden-file assertion.
 *
 * @readonly
 * @see Mirrors charmbracelet/bubbletea — TestResult value object (issue #1654)
 */
final readonly class TestResult
{
    /**
     * @param object          $model  Final model after all messages processed
     * @param string         $view   Last view() output string
     * @param list<\Closure> $cmds   All commands emitted during the run
     * @param string         $output Concatenated view() output across steps
     */
    public function __construct(
        public object $model,
        public string $view,
        public array $cmds,
        public string $output,
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
     * @param callable(\Closure): bool $filter Returns true for matching cmd
     * @throws \PHPUnit\Framework\AssertionFailedError if no cmd matches
     */
    public function assertCmdContains(callable $filter): void
    {
        foreach ($this->cmds as $cmd) {
            if ($filter($cmd)) {
                Assert::assertTrue(true);
                return;
            }
        }

        Assert::fail('No command matched the given filter.');
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

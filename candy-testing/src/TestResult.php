<?php

declare(strict_types=1);

namespace SugarCraft\Testing;

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
     * @param int $expected Expected command count
     * @throws \RuntimeException if count does not match
     */
    public function assertCmdCount(int $expected): void
    {
        $actual = count($this->cmds);
        if ($actual !== $expected) {
            throw new \RuntimeException(
                "Expected {$expected} commands, but got {$actual}"
            );
        }
    }

    /**
     * Assert that at least one command matches the given filter.
     *
     * @param callable(\Closure): bool $filter Returns true for matching cmd
     * @throws \RuntimeException if no cmd matches
     */
    public function assertCmdContains(callable $filter): void
    {
        foreach ($this->cmds as $cmd) {
            if ($filter($cmd)) {
                return;
            }
        }
        throw new \RuntimeException(
            "No command matched the given filter"
        );
    }

    /**
     * Assert that no commands were emitted.
     *
     * @throws \RuntimeException if any commands were emitted
     */
    public function assertNoCmds(): void
    {
        if (count($this->cmds) !== 0) {
            throw new \RuntimeException(
                "Expected no commands, but got " . count($this->cmds)
            );
        }
    }
}

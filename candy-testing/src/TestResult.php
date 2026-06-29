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
}

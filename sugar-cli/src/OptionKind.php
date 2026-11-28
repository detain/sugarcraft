<?php

declare(strict_types=1);

namespace SugarCraft\Cli;

/**
 * Whether an {@see Option} is a bare switch or carries a value.
 *
 * Original work: no charmbracelet upstream parses argv (fang hands that to
 * spf13/cobra), so this spec vocabulary is SugarCraft's own.
 */
enum OptionKind
{
    /** A switch: `--verbose`, `-v`. Repeatable flags count occurrences. */
    case Flag;

    /** Takes one value: `--model x`, `--model=x`, `-m x`, `-mx`. */
    case Value;
}

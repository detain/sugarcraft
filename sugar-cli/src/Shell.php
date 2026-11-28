<?php

declare(strict_types=1);

namespace SugarCraft\Cli;

/**
 * A shell {@see Completion::script()} can write a completion script for.
 * The backing value is the shell's usual name, so `Shell::tryFrom($argv[2])`
 * maps a `completion <shell>` argument.
 *
 * Original work: no charmbracelet upstream generates completions from a
 * spec (fang hands that to spf13/cobra).
 */
enum Shell: string
{
    case Bash = 'bash';
    case Zsh = 'zsh';
    case Fish = 'fish';
}

<?php

declare(strict_types=1);

namespace SugarCraft\Cli;

/**
 * Where a resolved option value in {@see ParsedArgs} came from.
 *
 * Original work: no charmbracelet upstream parses argv.
 */
enum ValueSource
{
    /** Given on the command line. */
    case Argv;

    /** Read from the option's environment variable ({@see Option::withEnv()}). */
    case Env;

    /** Neither was given; the option's {@see Option::withDefault()} value applies. */
    case Default;
}

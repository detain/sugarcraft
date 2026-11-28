<?php

declare(strict_types=1);

namespace SugarCraft\Cli;

/**
 * The PHP type a value {@see Option} converts its raw string into.
 *
 * Original work: no charmbracelet upstream parses argv.
 */
enum ValueType
{
    /** Kept as given. */
    case String;

    /** Optional sign then digits only (`-3`, `42`); anything else is a usage error. */
    case Int;

    /** Any numeric string PHP accepts (`1.5`, `-2`, `1e3`). */
    case Float;
}

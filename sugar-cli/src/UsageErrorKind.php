<?php

declare(strict_types=1);

namespace SugarCraft\Cli;

/**
 * Which parse rule a {@see UsageError} broke. Callers that localize their
 * CLI switch on this and {@see UsageError::params()} instead of the
 * English {@see UsageError::message()}.
 *
 * Original work: no charmbracelet upstream parses argv.
 */
enum UsageErrorKind
{
    /** `--name` or `-x` is not declared on the command (params: option, suggestion). */
    case UnknownOption;

    /** A positional where only a subcommand may stand (params: command, given, suggestion). */
    case UnknownCommand;

    /** A value option at the end of argv or followed by another option (params: option). */
    case MissingValue;

    /** `--flag=value` on a flag (params: option, value). */
    case UnexpectedValue;

    /** A value that does not convert to the option's type (params: option, value, expected, env). */
    case InvalidValue;

    /** A value outside the option's choices (params: option, value, choices, suggestion, env). */
    case InvalidChoice;

    /** A required option given neither on argv nor in its env variable (params: option, env). */
    case MissingOption;

    /** An option given without one it requires (params: option, requires). */
    case MissingRequirement;

    /** Two options of one conflict set given together (params: option, other). */
    case ConflictingOptions;

    /** Fewer positionals than the command's minimum (params: command, min, given). */
    case TooFewArguments;

    /** More positionals than the command's maximum (params: command, max, given). */
    case TooManyArguments;
}

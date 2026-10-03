<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Commands\Specs;

/**
 * What a built-in command's handler is handed, and so which spellings reach it.
 *
 * Declared per command in its spec file rather than inferred from the handler's
 * signature: `None` is also a ROUTING rule — `/exit now` and `/help me name
 * this` are prompts, not commands, because those handlers take no arguments and
 * their names must be the whole draft. See {@see BuiltInCommand::accepts()}.
 */
enum CommandArguments: string
{
    /** Bare name only; anything after it makes the draft a prompt. */
    case None = 'none';

    /**
     * The WHOLE trimmed draft. The handlers that take it do their own argument
     * parsing, and re-splitting here would be a second parse to keep in step.
     */
    case Text = 'text';

    /** {@see \SugarCraft\Crush\CommandParser}'s already-unquoted argument list. */
    case Parsed = 'parsed';
}

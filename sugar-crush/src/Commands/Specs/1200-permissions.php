<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;

// Category 'App', matching /keys and /budget: the gate is built once per LAUNCH
// by Cli\Bootstrap::permissionGate() and carried across /new, /clear and a
// provider switch by object identity, so filing it under Session would
// advertise a scope it does not have.
//
// Read-only on purpose, and there is no `<mode>` argumentHint to suggest
// otherwise. Changing the mode mid-session would hand a model that has just been
// refused a way to ask the user for the refusal to be lifted, in the same
// transcript; the mode comes from the flag, the env or the settings file, and
// this shows you which.
//
// Takes the WHOLE text, unlike `/keys`, which it otherwise resembles: measured,
// `permissions` once shipped argument-less and so `/permissions rules` went to
// the MODEL — a question about the local gate answered by the one participant
// that cannot see it. The argument is then IGNORED: the report is total, so
// every spelling gets a superset rather than a "no such subcommand".
return BuiltInCommand::new(CommandSpec::new(
    'permissions',
    'Show this session\'s permission mode, its source, and the rules it decides by',
    'App',
))->withHandler('handlePermissionsCommand');

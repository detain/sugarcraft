<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Lang;

// Category 'App', matching /keys and /budget: the gate is built once per LAUNCH
// by Cli\Bootstrap::permissionGate() and carried across /new, /clear and a
// provider switch by object identity, so filing it under Session would
// advertise a scope it does not have.
//
// The report is read-only; two subcommands change things, both typed by the
// user (the model cannot run a slash command): `mode <name>` switches the mode
// for the session's next turns — the in-session way to `accept-edits` or
// `auto` that Alt+M (plan only) does not give; `bypass-permissions` and
// `dont-ask` only when the launch chose them — and `revoke <n>|all` takes back
// the "always" grants the report lists.
//
// Takes the WHOLE text, unlike `/keys`, which it otherwise resembles: measured,
// `permissions` once shipped argument-less and so `/permissions rules` went to
// the MODEL — a question about the local gate answered by the one participant
// that cannot see it. Any other argument is IGNORED: the report is total, so
// every spelling gets a superset rather than a "no such subcommand".
return BuiltInCommand::new(CommandSpec::new(
    'permissions',
    Lang::t('cmd.permissions.description'),
    Lang::t('cmd.category.app'),
    argumentHint: Lang::t('cmd.permissions.hint'),
))->withHandler('handlePermissionsCommand')
    ->withHostCommand(\SugarCraft\Crush\Host\Commands\PermissionsCommand::class);

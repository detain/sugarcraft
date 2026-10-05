<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Lang;

// Deliberately NOT near `permissions` in category, though the two read alike.
// `/permissions` reports the gate that decides what a tool call may DO, and is
// read-only by design. `/rules` changes what the prompt SAYS and is a toggle on
// purpose: switching a rulebook off is exactly the correction a user should be
// able to make mid-session, and it reaches no authority, only prose. The hint is
// optional because both forms are real: `/rules` lists, `/rules terse` toggles.
return BuiltInCommand::new(CommandSpec::new(
    'rules',
    Lang::t('cmd.rules.description'),
    Lang::t('cmd.category.rules'),
    argumentHint: Lang::t('cmd.rules.hint'),
))->withHandler('handleRulesCommand')
    ->withHostCommand(\SugarCraft\Crush\Host\Commands\RulesHostCommand::class);

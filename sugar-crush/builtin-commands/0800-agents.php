<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Palette\PaletteAction;

// Both spellings: the row is `agents`, and `/agent` was reachable under the
// old prefix chain, so it stays reachable.
return BuiltInCommand::new(CommandSpec::new(
    'agents',
    'List active agents, or inspect one by name',
    'Agents',
    paletteAction: PaletteAction::SwitchAgent,
    paletteLabel: 'Switch agent',
))->withHandler('handleAgentsCommand')->withAliases('agent');

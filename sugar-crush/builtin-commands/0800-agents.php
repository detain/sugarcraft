<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Palette\PaletteAction;

// Both spellings: the row is `agents`, and `/agent` was reachable under the
// old prefix chain, so it stays reachable.
return BuiltInCommand::new(CommandSpec::new(
    'agents',
    Lang::t('cmd.agents.description'),
    Lang::t('cmd.category.agents'),
    paletteAction: PaletteAction::SwitchAgent,
    paletteLabel: Lang::t('cmd.agents.label'),
))->withHandler('handleAgentsCommand')->withAliases('agent')
    ->withHostCommand(\SugarCraft\Crush\Host\Commands\AgentsHostCommand::class);

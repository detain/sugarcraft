<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Palette\PaletteAction;

// Both spellings: the row is `agents`, and `/agent` was reachable under the
// old prefix chain, so it stays reachable.
return BuiltInCommand::new(CommandSpec::new(
    'agents',
    Lang::t('cmd.agents.description'),
    Lang::t('cmd.category.agents'),
    paletteAction: PaletteAction::SwitchAgent,
    paletteLabel: Lang::t('cmd.agents.label'),
    argumentHint: Lang::t('cmd.agents.hint'),
))->withHandler('handleAgentsCommand')->withAliases('agent')
    ->withHostCommand(\SugarCraft\Code\Host\Commands\AgentsHostCommand::class);

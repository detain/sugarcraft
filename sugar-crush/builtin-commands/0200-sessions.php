<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Palette\PaletteAction;

return BuiltInCommand::new(CommandSpec::new(
    'sessions',
    'List, search and manage sessions',
    'Session',
    paletteAction: PaletteAction::SwitchSession,
    paletteLabel: 'Switch session',
    argumentHint: '[<query>]',
))->withHandler('handleSessionsCommand');

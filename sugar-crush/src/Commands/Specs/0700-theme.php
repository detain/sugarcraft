<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Palette\PaletteAction;

return BuiltInCommand::new(CommandSpec::new(
    'theme',
    'Switch the color theme',
    'Appearance',
    paletteAction: PaletteAction::SwitchTheme,
    paletteLabel: 'Switch theme',
))->withHandler('handleThemeCommand');

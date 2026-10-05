<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Palette\PaletteAction;

return BuiltInCommand::new(CommandSpec::new(
    'theme',
    Lang::t('cmd.theme.description'),
    Lang::t('cmd.category.appearance'),
    paletteAction: PaletteAction::SwitchTheme,
    paletteLabel: Lang::t('cmd.theme.label'),
))->withHandler('handleThemeCommand');

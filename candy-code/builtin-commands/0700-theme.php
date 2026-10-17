<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Palette\PaletteAction;

return BuiltInCommand::new(CommandSpec::new(
    'theme',
    Lang::t('cmd.theme.description'),
    Lang::t('cmd.category.appearance'),
    paletteAction: PaletteAction::SwitchTheme,
    paletteLabel: Lang::t('cmd.theme.label'),
))->withHandler('handleThemeCommand');

<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Palette\PaletteAction;

return BuiltInCommand::new(CommandSpec::new(
    'layout',
    Lang::t('cmd.layout.description'),
    Lang::t('cmd.category.layout'),
    paletteAction: PaletteAction::LayoutReset,
    paletteLabel: Lang::t('cmd.layout.label'),
    argumentHint: Lang::t('cmd.layout.hint'),
))->withHandler('handleLayoutCommand');

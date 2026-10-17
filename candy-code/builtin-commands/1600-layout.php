<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Palette\PaletteAction;

return BuiltInCommand::new(CommandSpec::new(
    'layout',
    Lang::t('cmd.layout.description'),
    Lang::t('cmd.category.layout'),
    paletteAction: PaletteAction::LayoutReset,
    paletteLabel: Lang::t('cmd.layout.label'),
    argumentHint: Lang::t('cmd.layout.hint'),
))->withHandler('handleLayoutCommand');

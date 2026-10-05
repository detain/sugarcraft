<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Palette\PaletteAction;

return BuiltInCommand::new(CommandSpec::new(
    'sessions',
    Lang::t('cmd.sessions.description'),
    Lang::t('cmd.category.session'),
    paletteAction: PaletteAction::SwitchSession,
    paletteLabel: Lang::t('cmd.sessions.label'),
    argumentHint: Lang::t('cmd.sessions.hint'),
))->withHandler('handleSessionsCommand');

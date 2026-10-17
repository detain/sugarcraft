<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Palette\PaletteAction;

return BuiltInCommand::new(CommandSpec::new(
    'sessions',
    Lang::t('cmd.sessions.description'),
    Lang::t('cmd.category.session'),
    paletteAction: PaletteAction::SwitchSession,
    paletteLabel: Lang::t('cmd.sessions.label'),
    argumentHint: Lang::t('cmd.sessions.hint'),
))->withHandler('handleSessionsCommand');

<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Palette\PaletteAction;

// X-35a: a local export, `~/.candy-code/exports/` by default or a path inside
// the project. See ShareCommand.
return BuiltInCommand::new(CommandSpec::new(
    'share',
    Lang::t('cmd.share.description'),
    Lang::t('cmd.category.session'),
    paletteAction: PaletteAction::ShareSession,
    paletteLabel: Lang::t('cmd.share.label'),
    argumentHint: Lang::t('cmd.share.hint'),
))->withHandler('handleShareCommand')
    ->withHostCommand(\SugarCraft\Code\Host\Commands\ShareHostCommand::class);

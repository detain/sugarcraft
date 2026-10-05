<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Palette\PaletteAction;

// X-35a: a local export, `~/.sugar-crush/exports/` by default or a path inside
// the project. See ShareCommand.
return BuiltInCommand::new(CommandSpec::new(
    'share',
    Lang::t('cmd.share.description'),
    Lang::t('cmd.category.session'),
    paletteAction: PaletteAction::ShareSession,
    paletteLabel: Lang::t('cmd.share.label'),
    argumentHint: Lang::t('cmd.share.hint'),
))->withHandler('handleShareCommand')
    ->withHostCommand(\SugarCraft\Crush\Host\Commands\ShareHostCommand::class);

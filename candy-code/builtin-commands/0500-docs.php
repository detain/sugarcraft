<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Palette\PaletteAction;

// Palette-only, like `new`.
return BuiltInCommand::new(CommandSpec::new(
    'docs',
    Lang::t('cmd.docs.description'),
    Lang::t('cmd.category.app'),
    paletteAction: PaletteAction::OpenDocs,
    paletteLabel: Lang::t('cmd.docs.label'),
    slashVisible: false,
));

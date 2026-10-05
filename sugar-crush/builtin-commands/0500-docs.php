<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Palette\PaletteAction;

// Palette-only, like `new`.
return BuiltInCommand::new(CommandSpec::new(
    'docs',
    Lang::t('cmd.docs.description'),
    Lang::t('cmd.category.app'),
    paletteAction: PaletteAction::OpenDocs,
    paletteLabel: Lang::t('cmd.docs.label'),
    slashVisible: false,
));

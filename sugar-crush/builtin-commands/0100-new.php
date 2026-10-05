<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Palette\PaletteAction;

// Palette-only: no slash spelling dispatches it (typed, `/new` is a prompt).
return BuiltInCommand::new(CommandSpec::new(
    'new',
    Lang::t('cmd.new.description'),
    Lang::t('cmd.category.session'),
    paletteAction: PaletteAction::NewSession,
    paletteLabel: Lang::t('cmd.new.label'),
    slashVisible: false,
));

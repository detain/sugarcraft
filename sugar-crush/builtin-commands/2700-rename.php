<?php

declare(strict_types=1);

use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Commands\Specs\BuiltInCommand;
use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Palette\PaletteAction;

// P-A4: a bare `/rename` opens the inline title editor; `--auto` drops the title
// and asks the title model for a new one. The palette row opens the editor.
return BuiltInCommand::new(CommandSpec::new(
    'rename',
    Lang::t('cmd.rename.description'),
    Lang::t('cmd.category.session'),
    paletteAction: PaletteAction::RenameSession,
    paletteLabel: Lang::t('cmd.rename.label'),
    argumentHint: Lang::t('cmd.rename.hint'),
))->withHandler('handleRenameCommand')
    ->withHostCommand(\SugarCraft\Crush\Host\Commands\RenameCommand::class);

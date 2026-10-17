<?php

declare(strict_types=1);

use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\Specs\BuiltInCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Palette\PaletteAction;

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
    ->withHostCommand(\SugarCraft\Code\Host\Commands\RenameCommand::class);
